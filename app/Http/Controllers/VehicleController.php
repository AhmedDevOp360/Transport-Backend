<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        try {
            $query = Vehicle::with(['user']);

            // Filter by type
            if ($request->has('type') && !empty($request->type)) {
                $query->where('type', $request->type);
            }

            // Filter by status
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            // Search by vehicle details
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('vehicle_id', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%")
                        ->orWhere('license_plate', 'like', "%{$search}%");
                });
            }

            $vehicles = $query->orderBy('created_at', 'desc')->get();

            // Calculate statistics
            $totalVehicles = $vehicles->count();
            $availableVehicles = $vehicles->where('status', 'available')->count();
            $maintenanceAlerts = $vehicles->where('status', 'maintenance')->count();

            // Calculate utilization rate (placeholder calculation)
            $inUseVehicles = $vehicles->where('status', 'in-use')->count();
            $utilizationRate = $totalVehicles > 0 ? round(($inUseVehicles / $totalVehicles) * 100) : 0;

            // Format vehicle cards
            $vehicleCards = $vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->id,
                    'vehicle_id' => $vehicle->vehicle_id,
                    'name' => $vehicle->name,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year,
                    'type' => $vehicle->type,
                    'status' => $vehicle->status,
                    'image' => $vehicle->image,
                    'color' => $vehicle->color,
                    'license_plate' => $vehicle->license_plate,
                    'capacity_tons' => $vehicle->capacity_tons,
                    'rate_per_km' => $vehicle->rate_per_km,
                    'hourly_rate' => $vehicle->hourly_rate,
                    'last_used' => $vehicle->last_used,
                    'notes' => $vehicle->notes,
                    'owner' => [
                        'id' => $vehicle->user->id,
                        'name' => $vehicle->user->name,
                        'email' => $vehicle->user->email,
                    ],
                    'created_at' => $vehicle->created_at,
                ];
            });

            $data = [
                'statistics' => [
                    'total_vehicles' => $totalVehicles,
                    'available_vehicles' => $availableVehicles,
                    'maintenance_alerts' => $maintenanceAlerts,
                    'utilization_rate' => $utilizationRate,
                ],
                'vehicles' => $vehicleCards,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Vehicles retrieved successfully',
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'vehicle_id' => 'nullable|string|unique:vehicles,vehicle_id',
            'name' => 'nullable|string|max:255',
            'type' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'color' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'license_plate' => 'required|string|unique:vehicles,license_plate',
            'capacity_tons' => 'nullable|numeric|min:0',
            'rate_per_km' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:available,in-use,maintenance,retired',
            'notes' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $data = $request->only([
                'user_id',
                'vehicle_id',
                'name',
                'type',
                'model',
                'color',
                'year',
                'license_plate',
                'capacity_tons',
                'rate_per_km',
                'hourly_rate',
                'status',
                'notes',
            ]);

            // Generate vehicle_id if not provided
            if (empty($data['vehicle_id'])) {
                $data['vehicle_id'] = $this->generateVehicleId($request->type);
            }

            // Set default status
            $data['status'] = $data['status'] ?? 'available';

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = time() . '_' . $image->getClientOriginalName();
                $imagePath = $image->storeAs('vehicles', $imageName, 'public');
                $data['image'] = $imagePath;
            }

            $vehicle = Vehicle::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle created successfully',
                'data' => $vehicle->load('user'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        try {
            $vehicle = Vehicle::with(['user'])->find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found',
                ], 404);
            }

            $data = [
                'id' => $vehicle->id,
                'vehicle_id' => $vehicle->vehicle_id,
                'name' => $vehicle->name,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'type' => $vehicle->type,
                'status' => $vehicle->status,
                'image' => $vehicle->image,
                'color' => $vehicle->color,
                'license_plate' => $vehicle->license_plate,
                'capacity_tons' => $vehicle->capacity_tons,
                'rate_per_km' => $vehicle->rate_per_km,
                'hourly_rate' => $vehicle->hourly_rate,
                'last_used' => $vehicle->last_used,
                'notes' => $vehicle->notes,
                'owner' => [
                    'id' => $vehicle->user->id,
                    'name' => $vehicle->user->name,
                    'email' => $vehicle->user->email,
                    'phone' => $vehicle->user->phone,
                    'profile_image' => $vehicle->user->profile_image,
                ],
                'created_at' => $vehicle->created_at,
                'updated_at' => $vehicle->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Vehicle details retrieved successfully',
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicle details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'vehicle_id' => 'nullable|string|unique:vehicles,vehicle_id,' . $id,
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'license_plate' => 'nullable|string|unique:vehicles,license_plate,' . $id,
            'capacity_tons' => 'nullable|numeric|min:0',
            'rate_per_km' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:available,in-use,maintenance,retired',
            'notes' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found',
                ], 404);
            }

            $data = $request->only([
                'user_id',
                'vehicle_id',
                'name',
                'type',
                'model',
                'color',
                'year',
                'license_plate',
                'capacity_tons',
                'rate_per_km',
                'hourly_rate',
                'status',
                'notes',
            ]);

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($vehicle->image && Storage::disk('public')->exists($vehicle->image)) {
                    Storage::disk('public')->delete($vehicle->image);
                }

                $image = $request->file('image');
                $imageName = time() . '_' . $image->getClientOriginalName();
                $imagePath = $image->storeAs('vehicles', $imageName, 'public');
                $data['image'] = $imagePath;
            }

            // Update last_used if status changed to in-use
            if (isset($data['status']) && $data['status'] === 'in-use' && $vehicle->status !== 'in-use') {
                $data['last_used'] = Carbon::now()->toDateString();
            }

            $vehicle->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle updated successfully',
                'data' => $vehicle->fresh()->load('user'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            'status' => 'required|in:available,in-use,maintenance,retired',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found',
                ], 404);
            }

            // Update last_used if status changed to in-use
            $updateData = ['status' => $request->status];
            if ($request->status === 'in-use' && $vehicle->status !== 'in-use') {
                $updateData['last_used'] = Carbon::now()->toDateString();
            }

            $vehicle->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle status updated successfully',
                'data' => $vehicle->fresh()->load('user'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vehicle status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found',
                ], 404);
            }

            // Delete image if exists
            if ($vehicle->image && Storage::disk('public')->exists($vehicle->image)) {
                Storage::disk('public')->delete($vehicle->image);
            }

            $vehicle->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vehicle deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAlerts(): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        try {
            $alerts = [];

            // Check for maintenance status vehicles
            $maintenanceVehicles = Vehicle::where('status', 'maintenance')->get();

            foreach ($maintenanceVehicles as $vehicle) {
                $alerts[] = [
                    'type' => 'maintenance',
                    'severity' => 'warning',
                    'message' => $vehicle->name . ' (' . $vehicle->vehicle_id . '): Vehicle in maintenance',
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'custom_vehicle_id' => $vehicle->vehicle_id,
                ];
            }

            // Check for retired vehicles
            $retiredVehicles = Vehicle::where('status', 'retired')->get();

            foreach ($retiredVehicles as $vehicle) {
                $alerts[] = [
                    'type' => 'retired',
                    'severity' => 'info',
                    'message' => $vehicle->name . ' (' . $vehicle->vehicle_id . '): Vehicle retired',
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'custom_vehicle_id' => $vehicle->vehicle_id,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Vehicle alerts retrieved successfully',
                'data' => [
                    'total_alerts' => count($alerts),
                    'alerts' => $alerts,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicle alerts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPerformance(): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        try {
            $vehicles = Vehicle::all();

            // Group by type and calculate average performance metrics
            $performance = $vehicles->groupBy('type')->map(function ($typeVehicles, $type) {
                $totalVehicles = $typeVehicles->count();
                $availableCount = $typeVehicles->where('status', 'available')->count();

                // Calculate performance rating (based on availability and usage)
                $availabilityRate = $totalVehicles > 0 ? ($availableCount / $totalVehicles) * 5 : 0;

                return [
                    'type' => $type,
                    'rating' => round($availabilityRate, 1),
                    'total_vehicles' => $totalVehicles,
                    'available' => $availableCount,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Vehicle performance retrieved successfully',
                'data' => $performance,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicle performance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a unique vehicle ID based on type
     */
    private function generateVehicleId($type): string
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can access this request.',
            ], 403);
        }
        // Extract initials from type (e.g., "Medium Truck" -> "MT")
        $words = explode(' ', $type);
        $prefix = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $prefix .= strtoupper(substr($word, 0, 1));
            }
        }

        // Get the last vehicle ID with this prefix
        $lastVehicle = Vehicle::where('vehicle_id', 'like', $prefix . '%')
            ->orderBy('vehicle_id', 'desc')
            ->first();

        if ($lastVehicle) {
            // Extract number from last vehicle_id and increment
            preg_match('/(\d+)$/', $lastVehicle->vehicle_id, $matches);
            $number = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        } else {
            $number = 1;
        }

        return $prefix . ' - ' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}
