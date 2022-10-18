<?php

it('new', function () {
    $this->artisan('new --help')->assertExitCode(0);
});
