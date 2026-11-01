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
