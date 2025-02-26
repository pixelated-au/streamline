<?php

use Illuminate\Console\OutputStyle;
use Pixelated\Streamline\Actions\ProgressMeter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;

it('can run the progress meter with a known download total',
    /** @throws \JsonException */
    function () {
        $resource = fopen('php://memory', 'a+b');
        $output = new OutputStyle(
            new StringInput(''),
            new StreamOutput($resource),
        );

        $meter = new ProgressMeter($output, 0);

        $meter(100, 20);
        $meter(100, 50);
        $meter(100, 75);
        $meter(100, 100);
        $meter->finish();

        rewind($resource);

        /** @var string $output
         *  Output should resemble this:
         *    0/100 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
         *   20/100 [▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░]  20%
         *   50/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░]  50%
         *   75/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░]  75%
         *  100/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
         */
        $output = stream_get_contents($resource);

        /** @see https://pestphp.com/docs/snapshot-testing */
        expect($output)->toMatchSnapshot();

        $this->assertStringContainsString('0/100 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%', $output);
        $this->assertStringContainsString('20/100 [▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░]  20%', $output);
        $this->assertStringContainsString('50/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░]  50%', $output);
        $this->assertStringContainsString('75/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░]  75%', $output);
        $this->assertStringContainsString('100/100 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%', $output);
    });

it('can run the progress meter with an unknown download total',
    /** @throws \JsonException */
    function () {
        $resource = fopen('php://memory', 'a+b');
        $output = new OutputStyle(
            new StringInput(''),
            new StreamOutput($resource),
        );

        $meter = new ProgressMeter($output, 0);

        $meter(0, 20);
        $meter(0, 50);
        $meter(0, 75);
        $meter(0, 100);
        $meter->finish();

        rewind($resource);

        /** @var string $output
         *  Output should resemble this:
         *  0 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]
         *  1 [▓░░░░░░░░░░░░░░░░░░░░░░░░░░░]
         *  2 [▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░]
         *  3 [▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░]
         *  4 [▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░]
         */
        $output = stream_get_contents($resource);

        /** @see https://pestphp.com/docs/snapshot-testing */
        expect($output)->toMatchSnapshot();

        $this->assertStringContainsString('0 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]', $output);
        $this->assertStringContainsString('1 [▓░░░░░░░░░░░░░░░░░░░░░░░░░░░]', $output);
        $this->assertStringContainsString('2 [▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░]', $output);
        $this->assertStringContainsString('3 [▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░]', $output);
        $this->assertStringContainsString('4 [▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░]', $output);
    });

it('cannot run the progress meter as OutputStyle has not been defined', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('OutputStyle needs to be passed in if you want to use the ProgressMeter.');

    $meter = new ProgressMeter(null, 0);
    $meter(100, 20);
});

it(
    'should set message successfully when progress bar has started and is initialized',
    /**
     * @throws \JsonException
     */
    function () {
        $resource = fopen('php://memory', 'a+b');
        $output = new OutputStyle(
            new StringInput(''),
            new StreamOutput($resource)
        );

        $meter = new ProgressMeter($output, 0);
        $meter->setMessage('Test Message');
        $meter(0, 20);

        rewind($resource);
        $output = stream_get_contents($resource);

        expect($output)->toMatchSnapshot()
            ->and($output)->toContain('Test Message');
    });
