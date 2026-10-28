<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class MusicAssistantValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateConfiguratorModule(): void
    {
        $this->validateModule(__DIR__ . '/../MusicAssistantConfigurator');
    }
    public function testValidateConnectionModule(): void
    {
        $this->validateModule(__DIR__ . '/../MusicAssistantConnection');
    }
    public function testValidatePlayerModule(): void
    {
        $this->validateModule(__DIR__ . '/../MusicAssistantPlayer');
    }
}
