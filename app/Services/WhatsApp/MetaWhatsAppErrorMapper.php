<?php

namespace App\Services\WhatsApp;

final class MetaWhatsAppErrorMapper
{
    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public static function map(array $providerResponse, int $httpStatus = 0): string
    {
        if (isset($providerResponse['error']) && is_array($providerResponse['error'])) {
            return self::mapMetaError($providerResponse['error'], $httpStatus);
        }

        if ($httpStatus === 429) {
            return 'Rate Limit Exceeded. Meta has temporarily blocked requests. Please retry after a few minutes.';
        }

        if ($httpStatus >= 500) {
            return 'Meta WhatsApp Cloud API is temporarily unavailable. Please retry later.';
        }

        return (string) ($providerResponse['message'] ?? $providerResponse['raw'] ?? 'Meta WhatsApp Cloud API returned an unexpected error.');
    }

    /**
     * @param  array<string, mixed>  $error
     */
    public static function mapMetaError(array $error, int $httpStatus = 0): string
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = trim((string) ($error['message'] ?? ''));
        $type = (string) ($error['type'] ?? '');

        $mapped = match ($code) {
            190 => self::tokenError($subcode, $message),
            100 => self::invalidRequestError($message),
            132001 => 'Template Not Found or Language Mismatch. Verify the template name and language (en_US) are approved in Meta WhatsApp Manager.',
            132000 => 'Template Not Approved. The template must be approved by Meta before sending.',
            132015 => 'Template Paused. This template has been paused in Meta WhatsApp Manager.',
            131008 => 'Template Variable Missing. One or more template variables were empty. Ensure all required fields are filled before sending.',
            131026 => 'Invalid Phone Number. The recipient number is not a valid WhatsApp number.',
            131047 => 'Re-engagement Required. More than 24 hours have passed since the customer last replied. Use an approved template.',
            131051 => 'Unsupported Message Type. The message format is not supported for this recipient.',
            133010 => 'Phone Number Not Registered. The configured Phone Number ID is not registered with WhatsApp Business.',
            4, 80007 => 'Rate Limit Exceeded. Meta API rate limit reached. Please retry after a few minutes.',
            10 => 'Permission Denied. The access token does not have permission for this WhatsApp Business Account.',
            200 => 'Permission Error. The app does not have permission to access this resource.',
            default => null,
        };

        if ($mapped !== null) {
            return $message !== '' ? $mapped.' Meta response: '.$message : $mapped;
        }

        if ($httpStatus === 429 || in_array($code, [4, 80007], true)) {
            return 'Rate Limit Exceeded. '.$message;
        }

        if ($type === 'OAuthException' && $code === 190) {
            return self::tokenError($subcode, $message);
        }

        return $message !== ''
            ? $message.($code ? " (Meta error code: {$code})" : '')
            : 'Meta WhatsApp Cloud API returned an error.';
    }

    private static function tokenError(int $subcode, string $message): string
    {
        if ($subcode === 463 || str_contains(strtolower($message), 'expired')) {
            return 'Expired Token. The permanent access token has expired. Generate a new token in Meta Business Manager.';
        }

        if (str_contains(strtolower($message), 'invalid')) {
            return 'Invalid Access Token. Verify the permanent access token in WhatsApp settings.';
        }

        return 'Invalid Access Token. '.$message;
    }

    private static function invalidRequestError(string $message): string
    {
        if (str_contains(strtolower($message), 'phone number')) {
            return 'Invalid Phone Number ID. Verify the Phone Number ID in WhatsApp settings matches Meta Business Manager.';
        }

        return 'Invalid Request. '.$message;
    }
}
