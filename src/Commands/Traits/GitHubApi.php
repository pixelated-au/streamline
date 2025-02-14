<?php

namespace Pixelated\Streamline\Commands\Traits;

use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Commands\Attributes\GitHubToken;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[GitHubToken]
trait GitHubApi
{
    protected function configure(): void
    {
        $this->addOption(
            name: 'gh-token',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Extend the GitHub rate limits by specifying your auth token'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setGitHubAuthToken();

        return parent::execute($input, $output);
    }

    public function setGitHubAuthToken(): void
    {
        $token = $this->option('gh-token');
        if (empty($token)) {
            return;
        }

        Config::set('streamline.github_auth_token', $token);
    }
}
