<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\UserDocument;
use App\Models\AvailabilitySchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{

    public function index(){
    return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => User::with(['documents', 'vehicles', 'availabilitySchedules'])->find(Auth::id()),
            ]
        ], 200);
    }
    public function update(Request $request)
    {
        $user = $request->user();

        // Validation rules
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|string|unique:users,phone,' . $user->id,
            'country' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
            'profile_image' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:2048',

            // Documents
            'documents' => 'sometimes|array',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:5120',

            // Vehicle information
            'vehicle.type' => 'sometimes|string|max:255',
            'vehicle.model' => 'sometimes|string|max:255',
            'vehicle.color' => 'sometimes|string|max:255',
            'vehicle.year' => 'sometimes|integer|min:1900|max:' . (date('Y') + 1),
            'vehicle.license_plate' => 'sometimes|string|unique:vehicles,license_plate,' . optional($user->vehicles()->first())->id,
            'vehicle.rate_per_km' => 'sometimes|numeric|min:0',
            'vehicle.hourly_rate' => 'sometimes|numeric|min:0',

            // Availability Schedule
            'availability_schedules' => 'sometimes|array',
            'availability_schedules.*.day_of_week' => 'required_with:availability_schedules|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'availability_schedules.*.start_time' => 'required_with:availability_schedules|date_format:H:i',
            'availability_schedules.*.end_time' => 'required_with:availability_schedules|date_format:H:i|after:availability_schedules.*.start_time',
            'availability_schedules.*.is_available' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update basic user information
        $userData = $request->only(['name', 'email', 'phone', 'country', 'address']);

        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            // Delete old profile image if exists
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $profileImage = $request->file('profile_image');
            $profileImagePath = $profileImage->store('profile_images', 'public');
            $userData['profile_image'] = $profileImagePath;
        }

        $user->update($userData);

        // Handle document uploads
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $document) {
                $documentPath = $document->store('user_documents', 'public');

                UserDocument::create([
                    'user_id' => $user->id,
                    'document_path' => $documentPath,
                    'document_type' => $document->getClientMimeType(),
                    'original_filename' => $document->getClientOriginalName(),
                ]);
            }
        }

        // Handle vehicle information
        if ($request->has('vehicle')) {
            $vehicleData = $request->input('vehicle');
            $vehicleData['user_id'] = $user->id;

            // Check if user already has a vehicle, update it; otherwise create new
            $vehicle = $user->vehicles()->first();
            if ($vehicle) {
                $vehicle->update($vehicleData);
            } else {
                Vehicle::create($vehicleData);
            }
        }

        // Handle availability schedules
        if ($request->has('availability_schedules')) {
            // Delete existing schedules and create new ones
            $user->availabilitySchedules()->delete();

            foreach ($request->input('availability_schedules') as $schedule) {
                AvailabilitySchedule::create([
                    'user_id' => $user->id,
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'is_available' => $schedule['is_available'] ?? true,
                ]);
            }
        }

        // Reload user with relationships
        $user->load(['documents', 'vehicles', 'availabilitySchedules']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user,
            ]
        ], 200);
    }

}
