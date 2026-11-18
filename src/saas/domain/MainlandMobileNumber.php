<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\domain;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class MainlandMobileNumber
{
    private function __construct(public readonly string $e164)
    {
    }

    public static function fromInput(string $input): self
    {
        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $number = $phoneUtil->parse($input, 'CN');
        } catch (NumberParseException) {
            throw self::invalid();
        }

        if ($number->getCountryCode() !== 86
            || !$phoneUtil->isValidNumberForRegion($number, 'CN')
            || $phoneUtil->getNumberType($number) !== PhoneNumberType::MOBILE
            || $number->hasExtension()
        ) {
            throw self::invalid();
        }

        return new self($phoneUtil->format($number, PhoneNumberFormat::E164));
    }

    public function masked(): string
    {
        return str_pad(substr($this->e164, -4), strlen($this->e164), '*', STR_PAD_LEFT);
    }

    private static function invalid(): BusinessException
    {
        return new BusinessException('Expected a mainland China mobile number.', 'phone_invalid', 422);
    }
}
