"""Scheduled article contract exercised against the isolated WordPress server."""
import concurrent.futures
import copy
import hashlib
import json
import unittest
import urllib.request
import uuid
from integration import ReceiverIntegration, wp


class ArticleIntegration(unittest.TestCase):
    api = ReceiverIntegration.api

    @classmethod
    def setUpClass(cls):
        cls.fixture = json.loads(wp('option', 'get', 'avyo_fixture', '--format=json'))
        cls.editor, cls.subscriber = cls.fixture['editor'], cls.fixture['subscriber']
        wp('eval', 'Avyo_Articles::install();')

    def payload(self):
        identity = '01' + uuid.uuid4().hex[:24]
        return {'contract': 1, 'delivery_id': str(uuid.uuid4()), 'event': 'content.published',
                'project': {'slug': 'local-article-fixture', 'name': 'Local article fixture'},
                'content': {'id': identity, 'locale': 'en', 'locale_group_id': None,
                            'slug': 'scheduled-' + identity, 'title': 'Useful local cleaning article',
                            'summary': 'A useful guide to preparing for a cleaning visit.',
                            'html': '<h2>Prepare for your visit</h2><p>Clear access to the areas being cleaned.</p>',
                            'markdown': '## Prepare for your visit\nClear access to the areas being cleaned.',
                            'type': 'article', 'published_at': None, 'images': [], 'json_ld': None,
                            'faq_json_ld': None, 'author': None, 'internal_links': None}}

    def publish(self, payload):
        status, result = self.api('avyo/v1/articles', payload)
        self.assertEqual(200, status, result)
        return result

    def test_01_ping_and_permissions(self):
        payload = {**self.payload(), 'event': 'ping'}
        result = self.publish(payload)
        self.assertTrue(result['capabilities']['article_publish'])
        for auth in [False, self.subscriber]:
            self.assertEqual(403, self.api('avyo/v1/articles', payload, auth=auth)[0])

    def test_02_create_public_article_and_repeat_without_duplicate(self):
        payload = self.payload()
        result = self.publish(payload)
        self.assertEqual(payload['content']['id'], result['content_id'])
        self.assertEqual(result, self.publish(payload))
        _, post = self.api('wp/v2/posts/' + result['object_id'] + '?context=edit')
        self.assertEqual(payload['content']['html'], post['content']['raw'])
        self.assertEqual('publish', post['status'])
        public = urllib.request.urlopen(result['public_url']).read().decode()
        self.assertIn(payload['content']['title'], public)
        self.assertIn('<h2>Prepare for your visit</h2>', public)
        self.assertIn('<p>Clear access to the areas being cleaned.</p>', public)
        self.assertEqual(1, public.count('name="description"'))
        self.assertIn(payload['content']['summary'], public)
        expected = copy.deepcopy(payload['content']); expected.pop('published_at')
        digest = hashlib.sha256(json.dumps(expected, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()
        self.assertEqual(digest, result['content_hash'])
        payload['delivery_id'] = str(uuid.uuid4())
        self.assertEqual(result['object_id'], self.publish(payload)['object_id'])

    def test_03_update_same_article_preserves_permalink_and_author(self):
        payload = self.payload(); before = self.publish(payload)
        _, original = self.api('wp/v2/posts/' + before['object_id'] + '?context=edit')
        payload['event'], payload['delivery_id'] = 'content.updated', str(uuid.uuid4())
        payload['content']['title'] = 'A better guide to your cleaning visit'
        payload['content']['html'] += '<p>Keep pets away from cleaning products.</p>'
        payload['content']['slug'] = 'a-different-generated-slug'
        after = self.publish(payload)
        self.assertEqual(before['object_id'], after['object_id'])
        self.assertEqual(before['public_url'], after['public_url'])
        _, post = self.api('wp/v2/posts/' + after['object_id'] + '?context=edit')
        self.assertEqual(original['author'], post['author'])
        self.assertEqual(payload['content']['html'], post['content']['raw'])

    def test_04_external_edits_and_reused_identity_are_refused(self):
        payload = self.payload(); receipt = self.publish(payload)
        changed = copy.deepcopy(payload); changed['content']['title'] = 'Changed request'
        self.assertEqual(409, self.api('avyo/v1/articles', changed)[0])
        self.api('wp/v2/posts/' + receipt['object_id'], {'title': 'Website owner edited this'})
        changed['delivery_id'] = str(uuid.uuid4())
        self.assertEqual(409, self.api('avyo/v1/articles', changed)[0])
        self.assertEqual(receipt, self.publish(payload))
        _, post = self.api('wp/v2/posts/' + receipt['object_id'] + '?context=edit')
        self.assertEqual('Website owner edited this', post['title']['raw'])

    def test_05_invalid_markup_locale_or_missing_content_never_creates(self):
        for field, value in [('html', '<script>alert(1)</script>'), ('locale', 'pt'), ('title', ''), ('id', [])]:
            payload = self.payload(); payload['content'][field] = value
            self.assertEqual(422, self.api('avyo/v1/articles', payload)[0])

    def test_06_concurrent_duplicate_deliveries_return_one_identity(self):
        payload = self.payload()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: self.api('avyo/v1/articles', payload), range(2)))
        self.assertEqual([200, 200], [r[0] for r in results], results)
        self.assertEqual(results[0][1], results[1][1])

    def test_07_transformed_title_and_excerpt_roll_back_without_duplicate(self):
        for fault in ['1', 'unrelated']:
            payload = self.payload()
            wp('option', 'update', 'avyo_fixture_transform_source', fault)
            try:
                self.assertEqual(503, self.api('avyo/v1/articles', payload)[0])
            finally:
                wp('option', 'update', 'avyo_fixture_transform_source', '0')
            result = self.publish(payload)
            _, post = self.api('wp/v2/posts/' + result['object_id'] + '?context=edit')
            self.assertEqual(payload['content']['summary'], post['excerpt']['raw'])

    def test_08_failed_transaction_or_lock_never_overwrites(self):
        payload = self.payload(); before = self.publish(payload)
        for fault in ['begin', 'post_lock']:
            changed = copy.deepcopy(payload); changed['delivery_id'] = str(uuid.uuid4())
            changed['content']['title'] = 'Must not survive a failed transaction'
            wp('option', 'update', 'avyo_fixture_sql_fault', fault)
            try:
                self.assertEqual(503, self.api('avyo/v1/articles', changed)[0])
            finally:
                wp('option', 'update', 'avyo_fixture_sql_fault', '')
            _, post = self.api('wp/v2/posts/' + before['object_id'] + '?context=edit')
            self.assertEqual(payload['content']['title'], post['title']['raw'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
