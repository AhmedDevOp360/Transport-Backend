<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\PasswordResetToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\OtpMail;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Check if identifier is email or phone
     */
    private function isEmail($identifier)
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Send OTP via email or SMS
     */
    private function sendOtp($identifier, $otp, $userName = null, $purpose = 'verification')
    {
        try {
            if ($this->isEmail($identifier)) {
                // Send OTP via email
                Mail::to($identifier)->send(new OtpMail($otp, $userName, $purpose));
                Log::info("Email OTP sent to {$identifier}");
                return true;
            } else {
                // Send OTP via SMS
                $result = $this->smsService->sendOtp($identifier, $otp, $purpose);
                Log::info("SMS OTP sent to {$identifier}: " . json_encode($result));
                return $result['success'] ?? false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to send OTP to {$identifier}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Signup API
     * Accepts: name, identifier (email or phone), password
     */
    public function signup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'identifier' => 'required|string',
            'password' => 'required|string|min:8',
            'user_type' => 'required|string|in:customer,provider',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;
        $isEmail = $this->isEmail($identifier);

        // Check if user already exists
        if ($isEmail) {
            $existingUser = User::where('email', $identifier)->first();
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already registered'
                ], 422);
            }
        } else {
            $existingUser = User::where('phone', $identifier)->first();
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number already registered'
                ], 422);
            }
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = Carbon::now()->addMinutes(10);

        // Create user
        $userData = [
            'name' => $request->name,
            'password' => Hash::make($request->password),
            'user_type' => $request->user_type,
            'otp' => $otp,
            'otp_expires_at' => $otpExpiresAt,
            'is_verified' => false,
        ];

        if ($isEmail) {
            $userData['email'] = $identifier;
        } else {
            $userData['phone'] = $identifier;
        }

        $user = User::create($userData);

        // Send OTP
        $this->sendOtp($identifier, $otp, $user->name);

        return response()->json([
            'success' => true,
            'message' => $isEmail
                ? 'User registered successfully. Please check your email for OTP.'
                : 'User registered successfully. Please check your phone for OTP.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type,
                    'is_verified' => $user->is_verified,
                ],
                'verification_type' => $isEmail ? 'email' : 'sms',
                'otp' => $otp, // Remove this in production
            ]
        ], 201);
    }

    /**
     * Login API
     * Accepts: identifier (email or phone), password
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;
        $isEmail = $this->isEmail($identifier);

        // Find user by email or phone
        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
        } else {
            $user = User::where('phone', $identifier)->first();
        }

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user is verified
        if (!$user->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Account not verified. Please verify your account first.'
            ], 403);
        }

        // Create token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type,
                    'is_verified' => $user->is_verified,
                ],
                'token' => $token,
            ]
        ], 200);
    }

    /**
     * Forgot Password API
     * Accepts: identifier (email or phone)
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;
        $isEmail = $this->isEmail($identifier);

        // Find user by email or phone
        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
        } else {
            $user = User::where('phone', $identifier)->first();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = Carbon::now()->addMinutes(10);

        // Delete any existing reset token for this identifier
        PasswordResetToken::where('identifier', $identifier)->delete();

        // Store OTP in password_reset_tokens table
        PasswordResetToken::create([
            'identifier' => $identifier,
            'otp' => $otp,
            'otp_expires_at' => $otpExpiresAt,
            'created_at' => Carbon::now(),
        ]);

        // Send OTP
        $this->sendOtp($identifier, $otp, $user->name, 'reset_password');

        return response()->json([
            'success' => true,
            'message' => $isEmail
                ? 'OTP sent to your email successfully'
                : 'OTP sent to your phone successfully',
            'data' => [
                'verification_type' => $isEmail ? 'email' : 'sms',
                'otp' => $otp, // Remove this in production
            ]
        ], 200);
    }

    /**
     * Verify OTP API
     * Accepts: identifier, otp
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;
        $isEmail = $this->isEmail($identifier);

        // Find user by email or phone
        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
        } else {
            $user = User::where('phone', $identifier)->first();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if OTP matches
        if ($user->otp !== $request->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }

        // Check if OTP is expired
        if (Carbon::now()->greaterThan($user->otp_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired'
            ], 400);
        }

        // Mark user as verified and clear OTP
        $user->update([
            'is_verified' => true,
            'email_verified_at' => Carbon::now(),
            'otp' => null,
            'otp_expires_at' => null,
        ]);

        // Create token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account verified successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type,
                    'is_verified' => $user->is_verified,
                ],
                'token' => $token,
            ]
        ], 200);
    }

    /**
     * Resend OTP API
     * Accepts: identifier (email or phone)
     */
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;
        $isEmail = $this->isEmail($identifier);

        // Find user by email or phone
        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
        } else {
            $user = User::where('phone', $identifier)->first();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if already verified
        if ($user->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Account is already verified'
            ], 400);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = Carbon::now()->addMinutes(10);

        // Update user with new OTP
        $user->update([
            'otp' => $otp,
            'otp_expires_at' => $otpExpiresAt,
        ]);

        // Send OTP
        $this->sendOtp($identifier, $otp, $user->name);

        return response()->json([
            'success' => true,
            'message' => $isEmail
                ? 'OTP resent to your email successfully'
                : 'OTP resent to your phone successfully',
            'data' => [
                'verification_type' => $isEmail ? 'email' : 'sms',
                'otp' => $otp, // Remove this in production
            ]
        ], 200);
    }

    /**
     * Verify Forgot Password OTP API
     * Accepts: identifier, otp
     * Returns: reset_token
     */
    public function verifyForgotPasswordOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier;

        // Find password reset record by identifier
        $passwordReset = PasswordResetToken::where('identifier', $identifier)->first();

        if (!$passwordReset) {
            return response()->json([
                'success' => false,
                'message' => 'No password reset request found. Please request a new OTP.'
            ], 404);
        }

        // Check if OTP matches
        if ($passwordReset->otp !== $request->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }

        // Check if OTP is expired
        if (Carbon::now()->greaterThan($passwordReset->otp_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired'
            ], 400);
        }

        // Generate unique reset token
        $resetToken = bin2hex(random_bytes(32)); // 64 character token
        $resetTokenExpiresAt = Carbon::now()->addMinutes(30);

        // Update password reset record with reset token and clear OTP
        $passwordReset->update([
            'reset_token' => hash('sha256', $resetToken), // Store hashed version
            'reset_token_expires_at' => $resetTokenExpiresAt,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully',
            'data' => [
                'reset_token' => $resetToken, // Return plain token to client
                'expires_in' => 30 * 60, // 30 minutes in seconds
            ]
        ], 200);
    }

    /**
     * Reset Password API
     * Accepts: reset_token, new_password, confirm_password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string',
            'new_password' => 'required|string|min:8',
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Hash the provided token to compare with stored hash
        $hashedToken = hash('sha256', $request->reset_token);

        // Find password reset record by reset token
        $passwordReset = PasswordResetToken::where('reset_token', $hashedToken)->first();

        if (!$passwordReset) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token'
            ], 400);
        }

        // Check if reset token is expired
        if (Carbon::now()->greaterThan($passwordReset->reset_token_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired. Please request a new OTP.'
            ], 400);
        }

        // Find user by identifier
        $identifier = $passwordReset->identifier;
        $isEmail = $this->isEmail($identifier);

        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
        } else {
            $user = User::where('phone', $identifier)->first();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        // Delete the password reset record
        $passwordReset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
        ], 200);
    }
}
