<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Research\DataForSeo\DataForSeoClient;
use App\Visibility\DataForSeoLlmVisibility;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FullAiAnswerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('research.dataforseo.login', 'test');
        config()->set('research.dataforseo.password', 'test');
        config()->set('research.dataforseo.base_url', 'https://api.dataforseo.test');
    }

    #[Test]
    public function final_message_sections_keep_full_text_annotations_and_total_task_fee(): void
    {
        $long = str_repeat('An actual final answer. ', 100);
        Http::fake(['api.dataforseo.test/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'task-evidence', 'status_code' => 20000, 'cost' => .009,
            'result' => [['model_name' => 'resolved-model-202609', 'money_spent' => .004, 'web_search' => true, 'datetime' => '2026-09-15 12:00:00 +00:00',
                'items' => [['type' => 'reasoning', 'sections' => [['type' => 'text', 'text' => 'Private scratch reasoning.']]],
                    ['type' => 'message', 'sections' => [['type' => 'text', 'text' => $long, 'annotations' => [['url' => 'https://source.test/a', 'title' => 'Source', 'start_index' => 5, 'end_index' => 10]]],
                        ['type' => 'reasoning', 'text' => 'More private reasoning.'], ['type' => 'text', 'text' => 'Closing detail.']]]]]]]]])]);
        $answer = app(DataForSeoLlmVisibility::class)->ask('chat_gpt', 'Exact question?', 'pt', ['model' => 'pinned-alias', 'tag' => 'cell-id']);
        $this->assertNotNull($answer);
        $this->assertSame($long."\n\nClosing detail.", $answer->text);
        $this->assertSame(.009, $answer->totalCost);
        $this->assertSame(.004, $answer->moneySpent);
        $this->assertSame('resolved-model-202609', $answer->metadata['resolved_model']);
        $this->assertSame('pinned-alias', $answer->metadata['requested_model']);
        $this->assertSame('task-evidence', $answer->metadata['task_id']);
        $this->assertSame('not_reported', $answer->metadata['completion_state']);
        $this->assertSame(5, $answer->sections[0]['annotations'][0]['start_index']);
        $this->assertStringNotContainsString('reasoning', $answer->text);
        $this->assertSame('cell-id', Http::recorded()[0][0]->data()[0]['tag']);
    }

    #[Test]
    public function empty_answer_retains_envelope_and_unknown_cost_remains_null(): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'empty-id', 'status_code' => 20000, 'result' => [['items' => []]]]]])]);
        $answer = app(DataForSeoLlmVisibility::class)->ask('gemini', 'Question?', 'pt', ['preserve_empty' => true]);
        $this->assertNotNull($answer);
        $this->assertSame('', $answer->text);
        $this->assertNull($answer->totalCost);
        $this->assertNull($answer->metadata['resolved_model']);
        $this->assertNull($answer->metadata['sent_country']);
        $this->assertNull($answer->metadata['web_search_reported']);
    }

    #[Test]
    public function existing_research_post_callers_still_receive_result_lists(): void
    {
        $results = [['keywords' => ['cleaning']]];
        Http::fake(['api.dataforseo.test/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'legacy', 'status_code' => 20000, 'cost' => .1, 'result' => $results]]])]);
        $this->assertSame($results, app(DataForSeoClient::class)->post('/v3/keywords/live', ['keyword' => 'cleaning']));
    }
}
