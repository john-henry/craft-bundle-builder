<?php

/**
 * Pins that the install migration can run over an existing install without
 * failing: tables, indexes and foreign keys are only created when missing.
 */

use johnhenry\bundlebuilder\migrations\Install;

describe('Install migration', function () {
    it('runs again over an existing install without error', function () {
        expect((new Install())->safeUp())->toBeTrue();
    });
});
