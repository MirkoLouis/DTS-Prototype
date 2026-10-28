<?php

namespace Tests\Unit;

use App\Services\RouteSuggestionService;
use PHPUnit\Framework\TestCase;

class RouteSuggestionServiceTest extends TestCase
{
    // Official purpose with the given stored route (JSON string as read from the DB)
    private function official(string $route): array
    {
        return ['is_official' => 1, 'suggested_route' => $route];
    }

    // The guest's unit is prepended and flagged as injected
    public function test_official_purpose_prepends_guest_department(): void
    {
        $result = (new RouteSuggestionService())->suggest($this->official('["Cash Unit"]'), 'Personnel Unit');
        $this->assertSame([
            ['name' => 'Personnel Unit', 'is_injected' => true],
            ['name' => 'Cash Unit', 'is_injected' => false],
        ], $result);
    }

    // A guest unit that is already first is only flagged
    public function test_guest_department_not_duplicated_when_already_first(): void
    {
        $result = (new RouteSuggestionService())->suggest($this->official('["Cash Unit", "Budget Unit"]'), 'Cash Unit');
        $this->assertSame([
            ['name' => 'Cash Unit', 'is_injected' => true],
            ['name' => 'Budget Unit', 'is_injected' => false],
        ], $result);
    }

    // Non-official purposes never get a suggestion
    public function test_others_purpose_gets_no_suggestion(): void
    {
        $result = (new RouteSuggestionService())->suggest(['is_official' => 0, 'suggested_route' => '["Cash Unit"]'], 'Personnel Unit');
        $this->assertSame([], $result);
    }

    // Non-official purposes are never learned
    public function test_learning_skips_non_official_purpose(): void
    {
        $this->assertNull((new RouteSuggestionService())->learnedRoute(['is_official' => 0, 'suggested_route' => '[]'], ['Cash Unit'], null));
    }

    // The guest's unit is not stored as part of the learned route
    public function test_learning_strips_leading_guest_department(): void
    {
        $learned = (new RouteSuggestionService())->learnedRoute($this->official('[]'), ['Personnel Unit', 'Cash Unit'], 'Personnel Unit');
        $this->assertSame(['Cash Unit'], $learned);
    }

    // MySQL's canonical JSON has spaces after commas; semantic equality must still be detected
    public function test_learning_returns_null_when_route_unchanged_despite_mysql_json_spacing(): void
    {
        $learned = (new RouteSuggestionService())->learnedRoute($this->official('["Cash Unit", "Budget Unit"]'), ['Cash Unit', 'Budget Unit'], null);
        $this->assertNull($learned);
    }

    // Stored entries may be plain strings or {name: ...} objects
    public function test_handles_legacy_string_and_object_route_entries(): void
    {
        $service = new RouteSuggestionService();
        $result = $service->suggest($this->official('["Cash Unit", {"name": "Budget Unit"}]'), null);
        $this->assertSame([
            ['name' => 'Cash Unit', 'is_injected' => false],
            ['name' => 'Budget Unit', 'is_injected' => false],
        ], $result);
        $this->assertNull($service->learnedRoute($this->official('[{"name": "Cash Unit"}, "Budget Unit"]'), ['Cash Unit', 'Budget Unit'], null));
    }
}
