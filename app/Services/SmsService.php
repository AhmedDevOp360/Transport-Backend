<?php

namespace App\Services;

use Twilio\Rest\Client;
use Exception;

class SmsService
{
    protected $twilio;
    protected $from;

    public function __construct()
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $this->from = config('services.twilio.from');

        if ($accountSid && $authToken) {
            $this->twilio = new Client($accountSid, $authToken);
        }
    }

    /**
     * Send SMS to any international phone number
     *
     * @param string $to Phone number in E.164 format (e.g., +1234567890)
     * @param string $message Message to send
     * @return array
     */
    public function sendSms($to, $message)
    {
        try {
            // Format phone number to E.164 format if not already formatted
            $to = $this->formatPhoneNumber($to);

            if (!$this->twilio) {
                // If Twilio is not configured, log and return
                \Log::warning("Twilio not configured. SMS would be sent to {$to}: {$message}");

                return [
                    'success' => false,
                    'message' => 'SMS service not configured',
                    'debug' => $message // For development
                ];
            }

            $result = $this->twilio->messages->create(
                $to,
                [
                    'from' => $this->from,
                    'body' => $message
                ]
            );

            \Log::info("SMS sent successfully to {$to}. SID: {$result->sid}");

            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'sid' => $result->sid
            ];

        } catch (Exception $e) {
            \Log::error("Failed to send SMS to {$to}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to send SMS',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send OTP via SMS
     *
     * @param string $to Phone number
     * @param string $otp OTP code
     * @param string $purpose Purpose of OTP (verification, reset_password)
     * @return array
     */
    public function sendOtp($to, $otp, $purpose = 'verification')
    {
        $appName = config('app.name', 'Transport App');

        if ($purpose === 'reset_password') {
            $message = "{$otp} is your password reset OTP for {$appName}. Valid for 10 minutes. Do not share this code.";
        } else {
            $message = "{$otp} is your verification OTP for {$appName}. Valid for 10 minutes. Do not share this code.";
        }

        return $this->sendSms($to, $message);
    }

    /**
     * Format phone number to E.164 format
     *
     * @param string $phoneNumber
     * @return string
     */
    private function formatPhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters except +
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // If number doesn't start with +, add it
        if (substr($phoneNumber, 0, 1) !== '+') {
            // You can add default country code logic here if needed
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
    }

    /**
     * Verify if Twilio is configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->twilio !== null;
    }
}
