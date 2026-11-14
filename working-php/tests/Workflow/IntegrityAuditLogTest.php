<?php

namespace Tests\Workflow;

use App\Modules\System\SystemAuditService;
use Tests\Support\HttpClient;
use Tests\Support\IntegrityAudit;
use Tests\TestCase;

/**
 * The integrity check also verifies the system audit log, and System Overview shows the result.
 */
class IntegrityAuditLogTest extends TestCase
{
    // Every test starts with an audit log of two entries
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(SystemAuditService::path());
        SystemAuditService::record('backup_requested', ['user_id' => 1]);
        SystemAuditService::record('backup_deleted', ['file' => 'a.sql.zip', 'user_id' => 1]);
    }

    // Change what the second audit entry says, without the key
    private function damageSecondLine(): void
    {
        $lines = file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES);
        $lines[1] = str_replace('a.sql.zip', 'b.sql.zip', $lines[1]);
        file_put_contents(SystemAuditService::path(), implode("\n", $lines) . "\n");
    }

    // System Overview as the admin, after an integrity check has run
    private function overviewAfterCheck(): string
    {
        IntegrityAudit::run($this->fx['users']['admin']);
        $client = HttpClient::start();
        $client->loginAs('admin');

        return $client->get('/system-overview')['body'];
    }

    // An untouched audit log is reported as intact and keyed
    public function test_integrity_result_reports_an_intact_audit_log(): void
    {
        $results = IntegrityAudit::run($this->fx['users']['admin']);

        $this->assertSame(['keyed' => true, 'entries' => 2, 'bad_lines' => []], $results['audit_log']);
    }

    // An edited audit entry is reported by its line number
    public function test_integrity_result_reports_damaged_audit_lines(): void
    {
        $this->damageSecondLine();
        $results = IntegrityAudit::run($this->fx['users']['admin']);

        $this->assertSame([2], $results['audit_log']['bad_lines']);
    }

    // System Overview shows the audit log as intact
    public function test_system_overview_shows_an_intact_audit_log(): void
    {
        $body = $this->overviewAfterCheck();

        $this->assertStringContainsString('System audit log', $body);
        $this->assertMatchesRegularExpression('/System audit log<\/dt>\s*<dd[^>]*>\s*intact \(2 entries\)/', $body);
    }

    // System Overview names the damaged lines, and the overall status is no longer green
    public function test_system_overview_shows_damaged_audit_lines(): void
    {
        $this->damageSecondLine();
        $body = $this->overviewAfterCheck();

        $this->assertMatchesRegularExpression('/System audit log<\/dt>\s*<dd[^>]*>\s*altered: line 2/', $body);
        $this->assertMatchesRegularExpression('/text-red-500" id="verified-percentage"/', $body);
    }
}
