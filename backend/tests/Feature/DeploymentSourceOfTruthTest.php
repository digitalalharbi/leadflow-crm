<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Production has one road: PR → CI → develop → main → the "Deploy production" workflow →
 * the server fetches one exact commit from GitHub. These tests hold that shape: a later
 * change that opens a second road (an upload, a deploy from a machine, a develop default,
 * a server tree nobody checks) fails here, in CI, before it can reach a release.
 */
class DeploymentSourceOfTruthTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(base_path());
    }

    private function sh(string $command, ?string $cwd = null, array $env = []): array
    {
        $process = Process::fromShellCommandline($command, $cwd ?? $this->root, $env === [] ? null : $env);
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    public function test_the_deploy_path_is_github_only_main_only_and_guarded(): void
    {
        [$code, $output] = $this->sh('scripts/check-deploy-source.sh');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Production can only be released from GitHub', $output);
    }

    public function test_production_cannot_be_deployed_from_a_machine(): void
    {
        $this->assertFileDoesNotExist($this->root.'/scripts/release-production.sh');

        [$code, $output] = $this->sh('scripts/deploy-direct.sh production');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('production is not deployed from a machine', $output);
    }

    public function test_the_workflow_deploys_main_only_and_smoke_tests_after_the_health_check(): void
    {
        $workflow = File::get($this->root.'/.github/workflows/deploy-production.yml');
        $gate = File::get($this->root.'/scripts/vps/leadflow-deploy');

        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('branches: [main]', $workflow);
        $this->assertStringContainsString('DEPLOY_BRANCH=main', $gate);
        $this->assertStringContainsString('leadflow-deploy deploy', $workflow);
        $this->assertStringContainsString('DEPLOY_COMMIT', $workflow);
        $this->assertStringContainsString('ci-is-green.sh', $workflow);
        $this->assertGreaterThan(strpos($workflow, 'Verify production is healthy'), strpos($workflow, 'smoke-production.sh'));
        $this->assertStringNotContainsString('DEPLOY_BRANCH=develop', $workflow);
    }

    private function minutes(string $workflow, string $job): int
    {
        $yaml = File::get($this->root.'/.github/workflows/'.$workflow);
        $this->assertSame(1, preg_match('/^  '.$job.':\n(?:.*\n)*?    timeout-minutes: (\d+)/m', $yaml, $match), "{$workflow} {$job} has no time limit");

        return (int) $match[1];
    }

    /**
     * The three limits were written apart and drifted: the browser suite was cut off at 30 minutes
     * though it needs over two hours, and the gate stopped waiting after 10, so no release could
     * ever deploy — and each looked fine on its own.
     */
    public function test_ci_has_time_to_finish_the_gate_outwaits_ci_and_the_deploy_job_outlasts_both(): void
    {
        $e2e = $this->minutes('e2e-tests.yml', 'e2e');
        $this->assertGreaterThanOrEqual(150, $e2e, 'The whole suite needs about 130 minutes on the runner.');
        $this->assertLessThanOrEqual(360, $e2e);

        foreach (['deploy-production.yml', 'deploy-staging.yml'] as $workflow) {
            $yaml = File::get($this->root.'/.github/workflows/'.$workflow);
            $this->assertSame(1, preg_match('/ci-is-green\.sh "\$\{\{ github\.sha \}\}" (\d+)/', $yaml, $match), $workflow);
            $wait = (int) ceil(((int) $match[1]) / 60);

            $this->assertGreaterThan($e2e, $wait, "{$workflow} stops waiting before CI may finish");
            $this->assertGreaterThanOrEqual($wait + 25, $this->minutes($workflow, 'deploy'), "{$workflow} leaves no time to deploy after waiting");
            $this->assertLessThanOrEqual(360, $this->minutes($workflow, 'deploy'), 'GitHub stops a job at six hours.');
        }
    }

    public function test_ci_installs_every_browser_the_suite_runs_on_and_keeps_every_project(): void
    {
        $install = File::get($this->root.'/.github/workflows/e2e-tests.yml');
        $config = File::get($this->root.'/frontend/playwright.config.ts');

        $this->assertStringContainsString('playwright install --with-deps chromium firefox webkit', $install);
        $this->assertStringContainsString('include-hidden-files: true', $install, 'The report folders start with a dot: a failed run must leave its evidence.');

        foreach (['chromium', 'laptop', 'firefox', 'webkit', 'mobile-safari', 'mobile-chrome', 'mobile'] as $project) {
            $this->assertStringContainsString("name: '{$project}'", $config);
        }
    }

    /** @return array{0:string,1:string} the one stdout line, and the diagnostics */
    private function gate(array $runs, array $jobs = [], array $annotations = [], int $wait = 0): array
    {
        $dir = sys_get_temp_dir().'/ci-gate-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/runs.json', json_encode(['workflow_runs' => $runs]));
        file_put_contents($dir.'/jobs.json', json_encode(['jobs' => $jobs]));
        file_put_contents($dir.'/annotations.json', json_encode($annotations));
        // Stands in for GitHub: answers each endpoint from a file, through the real --jq.
        file_put_contents($dir.'/gh', <<<'SH'
        #!/usr/bin/env bash
        url="$2"; expr="$4"; dir="$(dirname "$0")"
        case "$url" in
          *workflows/ci.yml/runs*) file=runs ;;
          *check-runs*) file=annotations ;;
          *) file=jobs ;;
        esac
        jq -r "$expr" "$dir/$file.json"
        SH);
        chmod($dir.'/gh', 0755);

        $process = new Process(['bash', 'scripts/ci/ci-is-green.sh', 'abc123', (string) $wait], $this->root, [
            'PATH' => $dir.':'.getenv('PATH'), 'GITHUB_REPOSITORY' => 'o/r', 'CI_GATE_POLL' => '1', 'GITHUB_STEP_SUMMARY' => $dir.'/summary',
        ]);
        $process->run();
        File::deleteDirectory($dir);

        return [trim($process->getOutput()), $process->getErrorOutput()];
    }

    private function ciRun(string $status, ?string $conclusion, string $event = 'push'): array
    {
        return ['id' => 77, 'status' => $status, 'conclusion' => $conclusion, 'event' => $event];
    }

    private function job(string $name, string $conclusion): array
    {
        return ['id' => 5, 'name' => $name, 'status' => 'completed', 'conclusion' => $conclusion, 'started_at' => '2026-11-01T10:00:00Z', 'completed_at' => '2026-11-01T10:30:18Z'];
    }

    public function test_only_a_finished_successful_run_of_this_commit_is_green(): void
    {
        $this->assertSame('green=true', $this->gate([$this->ciRun('completed', 'success')])[0]);
        $this->assertSame('green=true', $this->gate([$this->ciRun('completed', 'success', 'workflow_dispatch')])[0]);

        foreach (['failure', 'cancelled', 'timed_out', 'skipped', 'neutral', 'startup_failure'] as $conclusion) {
            $this->assertSame('green=false', $this->gate([$this->ciRun('completed', $conclusion)])[0], $conclusion);
        }

        // Still going at the deadline, or never started.
        $this->assertSame('green=false', $this->gate([$this->ciRun('in_progress', null)])[0]);
        $this->assertSame('green=false', $this->gate([])[0]);
    }

    public function test_a_pull_request_on_the_same_commit_is_neither_its_answer_nor_hides_it(): void
    {
        // main syncing back into develop: a green pull request is not a green push.
        $this->assertSame('green=false', $this->gate([$this->ciRun('completed', 'success', 'pull_request')])[0]);
        $this->assertSame('green=false', $this->gate([$this->ciRun('completed', 'success', 'pull_request'), $this->ciRun('completed', 'failure')])[0]);
        $this->assertSame('green=true', $this->gate([$this->ciRun('in_progress', null, 'pull_request'), $this->ciRun('completed', 'success')])[0]);
    }

    public function test_a_refusal_names_the_workflow_job_conclusion_time_and_kind(): void
    {
        [$answer, $why] = $this->gate(
            [$this->ciRun('completed', 'failure')],
            [$this->job('Backend', 'success'), $this->job('Playwright', 'cancelled')],
            [['message' => 'The job running on runner has exceeded the maximum execution time of 30 minutes.']],
        );

        $this->assertSame('green=false', $answer, 'Diagnostics stay off stdout: the caller reads one line.');
        $this->assertStringContainsString('workflow "CI", run 77', $why);
        $this->assertStringContainsString('job: Backend | conclusion: success | elapsed: 30m18s | passed', $why);
        $this->assertStringContainsString('job: Playwright | conclusion: cancelled | elapsed: 30m18s | timed out', $why);

        [, $cancelled] = $this->gate([$this->ciRun('completed', 'cancelled')], [$this->job('Playwright', 'cancelled')]);
        $this->assertStringContainsString('conclusion: cancelled | elapsed: 30m18s | cancelled', $cancelled);

        [, $failed] = $this->gate([$this->ciRun('completed', 'failure')], [$this->job('Frontend', 'failure')]);
        $this->assertStringContainsString('conclusion: failure | elapsed: 30m18s | failed', $failed);

        [, $pending] = $this->gate([$this->ciRun('in_progress', null)]);
        $this->assertStringContainsString('(pending)', $pending);
    }

    public function test_the_image_never_receives_env_files_dependencies_tests_or_logs(): void
    {
        $ignored = array_map('trim', file($this->root.'/.dockerignore'));

        foreach (['.env', '**/.env', '**/.env.*', 'backend/vendor', 'backend/tests', 'backend/storage/logs/*', 'frontend/node_modules', 'frontend/dist', 'frontend/e2e', '**/*.log', 'backups'] as $entry) {
            $this->assertContains($entry, $ignored, "{$entry} must stay out of the image");
        }
    }

    // ------------------------------------------------------------ the server's working tree

    /** A server checkout: one commit, an ignored env file and a backups folder. */
    private function serverCheckout(): string
    {
        $dir = sys_get_temp_dir().'/leadflow-guard-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/.gitignore', ".env.*\n/backups\n");
        file_put_contents($dir.'/app.php', "<?php\n");

        foreach (['git init -q -b main', 'git add -A', 'git -c user.email=t@t -c user.name=t commit -qm one'] as $step) {
            $this->sh($step, $dir);
        }
        file_put_contents($dir.'/.env.production', "APP_KEY=secret\n");
        mkdir($dir.'/backups');
        file_put_contents($dir.'/backups/db.sql.gz', 'x');

        return $dir;
    }

    private function guard(string $dir): array
    {
        return $this->sh('. "'.$this->root.'/scripts/lib/worktree-guard.sh" && require_clean_worktree', $dir);
    }

    public function test_a_clean_server_tree_may_deploy_and_env_files_and_backups_do_not_count(): void
    {
        $dir = $this->serverCheckout();
        file_put_contents($dir.'/.last-good-commit', "abc\n");   // written by the last deploy

        [$code, $output] = $this->guard($dir);

        $this->assertSame(0, $code, $output);
        File::deleteDirectory($dir);
    }

    public function test_an_untracked_source_file_on_the_server_aborts_the_deploy(): void
    {
        $dir = $this->serverCheckout();
        file_put_contents($dir.'/hotfix.php', "<?php // edited on the server\n");

        [$code, $output] = $this->guard($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ABORT DEPLOY', $output);
        $this->assertStringContainsString('hotfix.php', $output);
        File::deleteDirectory($dir);
    }

    public function test_a_modified_source_file_on_the_server_aborts_the_deploy(): void
    {
        $dir = $this->serverCheckout();
        file_put_contents($dir.'/app.php', "<?php // changed by hand\n");

        [$code, $output] = $this->guard($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('app.php', $output);
        File::deleteDirectory($dir);
    }

    public function test_the_production_script_refuses_any_branch_but_main_before_touching_anything(): void
    {
        $dir = $this->serverCheckout();
        mkdir($dir.'/scripts');
        copy($this->root.'/scripts/deploy-production.sh', $dir.'/scripts/deploy-production.sh');
        mkdir($dir.'/scripts/lib');
        copy($this->root.'/scripts/lib/worktree-guard.sh', $dir.'/scripts/lib/worktree-guard.sh');
        $this->sh('git add -A && git -c user.email=t@t -c user.name=t commit -qm scripts', $dir);

        [$code, $output] = $this->sh('bash scripts/deploy-production.sh', $dir, ['PRODUCTION_PATH' => $dir, 'DEPLOY_BRANCH' => 'develop', 'PATH' => getenv('PATH')]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('production deploys only from main', $output);
        $this->assertStringNotContainsString('docker', strtolower($output), 'nothing was built or started');
        File::deleteDirectory($dir);
    }
}
