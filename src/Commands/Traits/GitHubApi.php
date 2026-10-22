<?php

namespace Pixelated\Streamline\Commands\Traits;

use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

trait GitHubApi
{
    protected function configure(): void
    {
        $this->addOption(
            name: 'gh-token',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Extend the GitHub rate limits by specifying your auth token. ' .
            'Prefer STREAMLINE_GITHUB_AUTH_TOKEN in your .env: a token passed here is visible to ' .
            'every other user on the machine via the process list, and is kept in your shell history.'
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

        $this->warn(
            'Warning: a token passed with --gh-token is visible to other users on this machine ' .
            'through the process list. Set STREAMLINE_GITHUB_AUTH_TOKEN in your .env instead.'
        );

        Config::set('streamline.github_auth_token', $token);
    }
}
