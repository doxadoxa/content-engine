"""Real HTTP/database WordPress checks. Only runs against the isolated local fixture."""

import base64
import concurrent.futures
import json
import pathlib
import socket
import subprocess
import unittest
import urllib.error
import urllib.request
import uuid


PACKAGE = pathlib.Path(__file__).resolve().parents[1]
COMPOSE = ["docker", "compose", "-f", str(PACKAGE / "fixture/compose.yaml")]
BASE = "http://localhost:8093"


def wp(*arguments):
    return subprocess.check_output(COMPOSE + ["run", "--rm", "-T", "cli", *arguments], text=True).strip()


class ReceiverIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = json.loads(wp("option", "get", "avyo_fixture", "--format=json"))
        cls.editor = cls.fixture["editor"]
        cls.subscriber = cls.fixture["subscriber"]

    def api(self, path, data=None, auth=True, method=None):
        headers = {}
        if auth:
            credentials = self.editor if auth is True else auth
            headers["Authorization"] = "Basic " + base64.b64encode(
                f'{credentials["username"]}:{credentials["password"]}'.encode()
            ).decode()
        body = json.dumps(data).encode() if data is not None else None
        if body is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(BASE + "/wp-json/" + path, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            return error.code, json.load(error)

    def create(self, content=None, title="Representative service page", kind="pages"):
        status, result = self.api("wp/v2/" + kind, {
            "title": title, "content": content or self.fixture["paragraph"] + "\n\n" + self.fixture["form"],
            "status": "publish", "excerpt": "Unrelated excerpt retained.", "slug": f"receiver-{kind}-{uuid.uuid4()}",
        })
        self.assertEqual(201, status, result)
        return str(result["id"])

    def source(self, identifier):
        status, result = self.api(f"avyo/v1/objects/{identifier}")
        self.assertEqual(200, status, result)
        return result

    def operation(self, identifier, source, patches=None, restore=None):
        payload = {"operation_id": str(uuid.uuid4()), "expected_revision": source["revision"]}
        payload.update({"restores_operation_id": restore} if restore else {"patches": patches})
        status, result = self.api(f"avyo/v1/objects/{identifier}/operations", payload)
        return status, result, payload

    def replace(self, field, before, after):
        return {"field": field, "operation": "replace", "before": before, "after": after}

    def test_01_read_real_core_source_and_capabilities(self):
        source = self.source(self.fixture["objects"]["service"])
        self.assertEqual(["title", "description", "body_html"], source["editable_fields"])
        self.assertEqual("page", source["object_type"])
        self.assertEqual(64, len(source["revision"]))
        self.assertIn("<!-- wp:paragraph -->", source["fields"]["body_html"])
        self.assertEqual("avyo", source["metadata"]["description_owner"])
        self.assertTrue(source["verification"]["public_fetch_required"])
        self.assertFalse(source["verification"]["api_success_is_verification"])
        self.assertIn("Raw post title", source["metadata"]["title_semantics"])

    def test_02_authentication_and_object_permission(self):
        path = f'avyo/v1/objects/{self.fixture["objects"]["service"]}'
        self.assertEqual(403, self.api(path, auth=False)[0])
        self.assertEqual(403, self.api(path, auth=self.subscriber)[0])
        self.assertIn(self.api(path, auth={"username": "avyo-editor", "password": "revoked"})[0], [401, 403])

    def test_03_publish_core_fields_and_public_result_preserving_forms_and_excerpt(self):
        identifier = self.create()
        before = self.source(identifier)
        patches = [
            self.replace("title", before["fields"]["title"], "Reviewed cleaning service"),
            self.replace("description", "", "A reviewed service description."),
            self.replace("body_html", "include the kitchen and bathroom", "cover the kitchen and bathroom"),
        ]
        status, result, _ = self.operation(identifier, before, patches)
        self.assertEqual(200, status, result)
        after = self.source(identifier)
        self.assertEqual(result["after"]["revision"], after["revision"])
        self.assertEqual(before["public_url"], after["public_url"])
        self.assertIn(self.fixture["form"], after["fields"]["body_html"])
        self.assertEqual(before["fields"]["body_html"].replace("include the kitchen and bathroom", "cover the kitchen and bathroom"), after["fields"]["body_html"])
        self.assertIsNotNone(after["core_revision_id"])
        _, core = self.api(f"wp/v2/pages/{identifier}?context=edit")
        self.assertEqual("Unrelated excerpt retained.", core["excerpt"]["raw"])
        public = urllib.request.urlopen(after["public_url"]).read().decode()
        self.assertIn("Reviewed cleaning service", public)
        self.assertIn("cover the kitchen and bathroom", public)
        self.assertEqual(1, public.count('name="description"'))
        self.assertIn('content="A reviewed service description."', public)
        self.assertIn('name="name" required', public)
        self.assertEqual("required", result["public_verification"])

    def test_04_retry_identity_and_external_revision_conflict(self):
        identifier = self.create(kind="posts")
        before = self.source(identifier)
        status, original, payload = self.operation(identifier, before, [self.replace("title", before["fields"]["title"], "Reviewed article title")])
        self.assertEqual(200, status, original)
        self.assertEqual(original, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[1])
        self.api(f"wp/v2/posts/{identifier}", {"title": "External editor title"})
        self.assertEqual(original, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[1])
        self.assertEqual("External editor title", self.source(identifier)["fields"]["title"])
        status, conflict, _ = self.operation(identifier, before, [self.replace("title", before["fields"]["title"], "Stale overwrite")])
        self.assertEqual((409, "avyo_revision_conflict"), (status, conflict["code"]))
        payload["patches"][0]["after"] = "Different approved request"
        self.assertEqual(409, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[0])

    def test_05_exact_recovery_and_recovery_retry(self):
        identifier = self.create()
        before = self.source(identifier)
        _, publication, _ = self.operation(identifier, before, [self.replace("description", "", "Temporary approved description."), self.replace("title", before["fields"]["title"], "Temporary title")])
        status, recovered, payload = self.operation(identifier, self.source(identifier), restore=publication["operation_id"])
        self.assertEqual(200, status, recovered)
        self.assertEqual(before["fields"], recovered["after"]["fields"])
        self.assertFalse(recovered["after"]["metadata"]["description_present"])
        self.assertEqual(recovered, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[1])
        self.assertEqual(before["public_url"], recovered["after"]["public_url"])

    def test_06_recovery_never_overwrites_external_edit(self):
        identifier = self.create()
        before = self.source(identifier)
        _, publication, _ = self.operation(identifier, before, [self.replace("title", before["fields"]["title"], "Published title")])
        self.api(f"wp/v2/pages/{identifier}", {"title": "External edit after publication"})
        status, conflict, _ = self.operation(identifier, self.source(identifier), restore=publication["operation_id"])
        self.assertEqual((409, "avyo_recovery_conflict"), (status, conflict["code"]))
        self.assertEqual("External edit after publication", self.source(identifier)["fields"]["title"])

    def test_07_external_unrelated_metadata_blocks_stale_write_and_is_preserved(self):
        identifier = self.create()
        before = self.source(identifier)
        wp("post", "meta", "update", identifier, "unrelated_business_setting", "external-value")
        status, _, _ = self.operation(identifier, before, [self.replace("title", before["fields"]["title"], "Stale title")])
        self.assertEqual(409, status)
        current = self.source(identifier)
        status, _, _ = self.operation(identifier, current, [self.replace("title", current["fields"]["title"], "Fresh approved title")])
        self.assertEqual(200, status)
        self.assertEqual("external-value", wp("post", "meta", "get", identifier, "unrelated_business_setting"))

    def test_08_unsupported_builder_dynamic_block_and_seo_owner(self):
        for fixture, field in [("builder", "title"), ("dynamic", "body_html"), ("seo_owned", "description")]:
            identifier = self.fixture["objects"][fixture]
            source = self.source(identifier)
            self.assertNotIn(field, source["editable_fields"])
            status, _, _ = self.operation(identifier, source, [self.replace(field, source["fields"][field], "Unsupported overwrite")])
            self.assertEqual(422, status)
        self.assertEqual("SEO plugin owns this description.", wp("post", "meta", "get", str(self.fixture["objects"]["seo_owned"]), "_yoast_wpseo_metadesc"))

    def test_09_description_ownership_is_explicit(self):
        identifier = self.create()
        wp("option", "update", "avyo_owns_description", "0")
        try:
            source = self.source(identifier)
            self.assertNotIn("description", source["editable_fields"])
            self.assertEqual("unassigned", source["metadata"]["description_owner"])
        finally:
            wp("option", "update", "avyo_owns_description", "1")

    def test_10_reject_markup_form_attribute_and_duplicate_changes(self):
        identifier = self.fixture["objects"]["classic"]
        source = self.source(identifier)
        for old, new in [("Protected form paragraph", "Changed form"), ('title="a > b and <p> hidden"', 'title="changed"'), ("<p>Read about", "<h2>Read about")]:
            status, _, _ = self.operation(identifier, source, [self.replace("body_html", old, new)])
            self.assertEqual(422, status)
        duplicate = self.fixture["objects"]["duplicate"]
        status, result, _ = self.operation(duplicate, self.source(duplicate), [self.replace("body_html", "Same anchor", "New anchor")])
        self.assertEqual((422, "avyo_ambiguous_fragment"), (status, result["code"]))

    def test_11_bounded_link_and_top_level_core_insertion(self):
        identifier = self.create(kind="posts")
        source = self.source(identifier)
        status, result, _ = self.operation(identifier, source, [{"field": "body_html", "operation": "link", "before": "Home cleaning visits", "after": "https://example.com/services"}])
        self.assertEqual(200, status, result)
        self.assertIn('<a href="https://example.com/services">Home cleaning visits</a>', result["after"]["fields"]["body_html"])
        status, _, _ = self.operation(identifier, self.source(identifier), [{"field": "body_html", "operation": "link", "before": "Home cleaning visits", "after": "https://example.com/other"}])
        self.assertEqual(422, status)
        other = self.create()
        paragraph = '<!-- wp:paragraph -->\n<p>Request a visit using the existing form.</p>\n<!-- /wp:paragraph -->'
        status, result, _ = self.operation(other, self.source(other), [{"field": "body_html", "operation": "insert_after", "before": self.fixture["paragraph"], "after": paragraph}])
        self.assertEqual(200, status, result)
        self.assertIn(self.fixture["form"], result["after"]["fields"]["body_html"])
        self.assertIn(paragraph, result["after"]["fields"]["body_html"])

    def test_12_transformed_source_rolls_back_operation_and_content(self):
        identifier = self.create()
        source = self.source(identifier)
        wp("option", "update", "avyo_fixture_transform_source", "1")
        try:
            status, result, payload = self.operation(identifier, source, [self.replace("title", source["fields"]["title"], "Approved unchanged bytes")])
            self.assertEqual((409, "avyo_source_transformed"), (status, result["code"]))
            self.assertEqual(source["revision"], self.source(identifier)["revision"])
            self.assertEqual(404, self.api("avyo/v1/operations/" + payload["operation_id"])[0])
        finally:
            wp("option", "update", "avyo_fixture_transform_source", "0")

    def test_13_lost_response_is_reconciled_without_second_write(self):
        identifier = self.create()
        source = self.source(identifier)
        payload = {"operation_id": str(uuid.uuid4()), "expected_revision": source["revision"], "patches": [self.replace("title", source["fields"]["title"], "Response was deliberately discarded")]}
        encoded = json.dumps(payload).encode()
        auth = base64.b64encode(f'{self.editor["username"]}:{self.editor["password"]}'.encode()).decode()
        request = (f"POST /wp-json/avyo/v1/objects/{identifier}/operations HTTP/1.1\r\nHost: localhost:8093\r\nAuthorization: Basic {auth}\r\nContent-Type: application/json\r\nContent-Length: {len(encoded)}\r\nConnection: close\r\n\r\n").encode() + encoded
        with socket.create_connection(("localhost", 8093), timeout=5) as connection:
            connection.sendall(request)
            connection.shutdown(socket.SHUT_WR)
            # Wait for the server to begin its response, then discard all of it.
            connection.recv(1)
        status, operation = self.api("avyo/v1/operations/" + payload["operation_id"])
        self.assertEqual(200, status, operation)
        self.assertEqual(operation, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[1])
        self.assertEqual(operation["after"]["revision"], self.source(identifier)["revision"])

    def test_14_concurrent_requests_cannot_overwrite_same_revision(self):
        identifier = self.create()
        source = self.source(identifier)
        def publish(title):
            return self.operation(identifier, source, [self.replace("title", source["fields"]["title"], title)])[0]
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            statuses = list(pool.map(publish, ["Concurrent approved A", "Concurrent approved B"]))
        self.assertEqual([200, 409], sorted(statuses))

    def test_15_unapproved_filter_change_rolls_back_every_field(self):
        identifier = self.create()
        source = self.source(identifier)
        wp("option", "update", "avyo_fixture_transform_source", "unrelated")
        try:
            status, result, payload = self.operation(identifier, source, [self.replace("title", source["fields"]["title"], "Approved title only")])
            self.assertEqual((409, "avyo_unrelated_source_changed"), (status, result["code"]))
            self.assertEqual(source["revision"], self.source(identifier)["revision"])
            _, core = self.api(f"wp/v2/pages/{identifier}?context=edit")
            self.assertEqual("Unrelated excerpt retained.", core["excerpt"]["raw"])
            self.assertEqual(404, self.api("avyo/v1/operations/" + payload["operation_id"])[0])
        finally:
            wp("option", "update", "avyo_fixture_transform_source", "0")

    def test_16_malformed_and_unapproved_fields_do_not_write(self):
        identifier = self.create()
        source = self.source(identifier)
        for extra in [{"operation_id": []}, {"expected_revision": []}, {"restores_operation_id": []}, {"status": "publish"}]:
            payload = {"operation_id": str(uuid.uuid4()), "expected_revision": source["revision"], "patches": [self.replace("title", source["fields"]["title"], "Rejected title")], **extra}
            self.assertEqual(422, self.api(f"avyo/v1/objects/{identifier}/operations", payload)[0])
        self.assertEqual(source["revision"], self.source(identifier)["revision"])

    def test_17_post_page_permalink_collision_requires_assistance(self):
        slug = "shared-core-slug-" + str(uuid.uuid4())
        ids = []
        for kind in ["pages", "posts"]:
            status, result = self.api("wp/v2/" + kind, {"title": "Shared slug", "content": "<p>Page or post</p>", "slug": slug, "status": "publish"})
            self.assertEqual(201, status, result)
            ids.append(str(result["id"]))
        sources = [self.source(identifier) for identifier in ids]
        self.assertEqual(sources[0]["public_url"], sources[1]["public_url"])
        self.assertTrue(any(not source["editable_fields"] and "exact core object" in source["metadata"]["unsupported_reason"] for source in sources))

    def test_18_real_yoast_activation_transfers_description_ownership(self):
        identifier = self.create()
        source = self.source(identifier)
        status, result, _ = self.operation(identifier, source, [self.replace("description", "", "Avyo-only description before SEO-plugin activation.")])
        self.assertEqual(200, status, result)
        wp("plugin", "activate", "wordpress-seo")
        try:
            source = self.source(identifier)
            self.assertEqual("seo_plugin", source["metadata"]["description_owner"])
            self.assertNotIn("description", source["editable_fields"])
            status, _, _ = self.operation(identifier, source, [self.replace("description", source["fields"]["description"], "Must not overwrite Yoast")])
            self.assertEqual(422, status)
            public = urllib.request.urlopen(source["public_url"]).read().decode()
            self.assertNotIn('content="Avyo-only description before SEO-plugin activation."', public)
            self.assertIn("Yoast SEO", public)
        finally:
            wp("plugin", "deactivate", "wordpress-seo")
        after = self.source(identifier)
        self.assertEqual("Avyo-only description before SEO-plugin activation.", after["fields"]["description"])

    def test_19_external_edit_back_to_same_text_still_changes_revision(self):
        identifier = self.create()
        before = self.source(identifier)
        self.api(f"wp/v2/pages/{identifier}", {"title": "Intermediate external edit"})
        self.api(f"wp/v2/pages/{identifier}", {"title": before["fields"]["title"]})
        after = self.source(identifier)
        self.assertEqual(before["fields"], after["fields"])
        self.assertNotEqual(before["core_revision_id"], after["core_revision_id"])
        self.assertNotEqual(before["revision"], after["revision"])
        self.assertEqual(409, self.operation(identifier, before, [self.replace("title", before["fields"]["title"], "Stale after revert")])[0])

    def test_20_failed_begin_or_lock_never_writes_source_or_journal(self):
        identifier = self.create()
        source = self.source(identifier)
        for fault in ["begin", "post_lock", "meta_lock", "term_lock"]:
            with self.subTest(fault=fault):
                wp("option", "update", "avyo_fixture_sql_fault", fault)
                try:
                    status, result, payload = self.operation(identifier, source, [self.replace("title", source["fields"]["title"], "Must never survive failed lock")])
                    self.assertEqual((503, "avyo_outcome_unknown"), (status, result["code"]))
                finally:
                    wp("option", "update", "avyo_fixture_sql_fault", "")
                self.assertEqual(source["revision"], self.source(identifier)["revision"])
                self.assertEqual(404, self.api("avyo/v1/operations/" + payload["operation_id"])[0])

    def test_21_failed_required_reads_never_return_a_plausible_snapshot(self):
        identifier = self.create()
        source = self.source(identifier)
        for fault in ["meta_read", "revision_read", "operation_read"]:
            with self.subTest(fault=fault):
                wp("option", "update", "avyo_fixture_sql_fault", fault)
                try:
                    if fault != "operation_read":
                        status, result = self.api(f"avyo/v1/objects/{identifier}")
                        self.assertEqual((503, "avyo_read_failed"), (status, result["code"]))
                        self.assertNotIn("fields", result)
                    else:
                        status, result = self.api("avyo/v1/operations/" + str(uuid.uuid4()))
                        self.assertEqual((503, "avyo_read_failed"), (status, result["code"]))
                    status, result, payload = self.operation(identifier, source, [self.replace("title", source["fields"]["title"], "Must never survive failed read")])
                    self.assertEqual(503, status, result)
                finally:
                    wp("option", "update", "avyo_fixture_sql_fault", "")
                self.assertEqual(source["revision"], self.source(identifier)["revision"])
                self.assertEqual(404, self.api("avyo/v1/operations/" + payload["operation_id"])[0])


if __name__ == "__main__":
    unittest.main(verbosity=2)
