<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Driver::with(['user']);

            // Filter by status
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            // Search by team name
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('team_name', 'like', "%{$search}%")
                        ->orWhere('truck_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $drivers = $query->orderBy('created_at', 'desc')->get();

            // Calculate statistics
            $totalDrivers = $drivers->count();
            $completedToday = $drivers->sum('completed_jobs');
            $activeJobs = $drivers->where('status', 'active')->count() +
                         $drivers->where('status', 'in-transit')->count();

            // Calculate completion rate
            $totalJobs = $drivers->sum('completed_jobs');
            $completionRate = $totalJobs > 0 ? 94 : 0; // Placeholder calculation

            // Format driver cards
            $driverCards = $drivers->map(function ($driver) {
                return [
                    'id' => $driver->id,
                    'team_name' => $driver->team_name,
                    'status' => $driver->status,
                    'team_leader' => [
                        'id' => $driver->user->id,
                        'name' => $driver->user->name,
                        'email' => $driver->user->email,
                        'phone' => $driver->user->phone,
                        'profile_image' => $driver->user->profile_image,
                    ],
                    'job_assignment' => $driver->job_assignment,
                    'truck_number' => $driver->truck_number,
                    'rating' => $driver->rating,
                    'completed_jobs' => $driver->completed_jobs,
                    'created_at' => $driver->created_at,
                ];
            });

            $data = [
                'statistics' => [
                    'total_drivers' => $totalDrivers,
                    'completed_today' => $completedToday,
                    'active_jobs' => $activeJobs,
                    'completion_rate' => $completionRate,
                ],
                'drivers' => $driverCards,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Drivers retrieved successfully',
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve drivers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validate = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'team_name' => 'required|string|max:255',
            'status' => 'nullable|in:active,in-transit,on-break,available',
            'job_assignment' => 'nullable|string|max:255',
            'truck_number' => 'required|string|unique:drivers,truck_number',
            'rating' => 'nullable|numeric|min:0|max:5',
            'license_expiry' => 'nullable|date',
            'vehicle_maintenance_due' => 'nullable|date',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $driver = Driver::create([
                'user_id' => $request->user_id,
                'team_name' => $request->team_name,
                'status' => $request->status ?? 'available',
                'job_assignment' => $request->job_assignment,
                'truck_number' => $request->truck_number,
                'rating' => $request->rating ?? 0.0,
                'license_expiry' => $request->license_expiry,
                'vehicle_maintenance_due' => $request->vehicle_maintenance_due,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Driver created successfully',
                'data' => $driver->load('user'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $driver = Driver::with(['user', 'assignedVehicle'])->find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $data = [
                'id' => $driver->id,
                'team_name' => $driver->team_name,
                'status' => $driver->status,
                'team_leader' => [
                    'id' => $driver->user->id,
                    'name' => $driver->user->name,
                    'email' => $driver->user->email,
                    'phone' => $driver->user->phone,
                    'profile_image' => $driver->user->profile_image,
                    'address' => $driver->user->address,
                    'country' => $driver->user->country,
                ],
                'job_assignment' => $driver->job_assignment,
                'truck_number' => $driver->truck_number,
                'rating' => $driver->rating,
                'license_expiry' => $driver->license_expiry,
                'vehicle_maintenance_due' => $driver->vehicle_maintenance_due,
                'completed_jobs' => $driver->completed_jobs,
                'assigned_vehicle' => $driver->assignedVehicle ? [
                    'id' => $driver->assignedVehicle->id,
                    'vehicle_name' => $driver->assignedVehicle->vehicle_name,
                    'plate_number' => $driver->assignedVehicle->plate_number,
                    'vehicle_type' => $driver->assignedVehicle->vehicle_type,
                    'capacity_tons' => $driver->assignedVehicle->capacity_tons,
                    'model' => $driver->assignedVehicle->model,
                    'year' => $driver->assignedVehicle->year,
                    'last_used' => $driver->assignedVehicle->last_used,
                    'status' => $driver->assignedVehicle->status,
                ] : null,
                'created_at' => $driver->created_at,
                'updated_at' => $driver->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Driver details retrieved successfully',
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve driver details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validate = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'team_name' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,in-transit,on-break,available',
            'job_assignment' => 'nullable|string|max:255',
            'truck_number' => 'nullable|string|unique:drivers,truck_number,' . $id,
            'rating' => 'nullable|numeric|min:0|max:5',
            'license_expiry' => 'nullable|date',
            'vehicle_maintenance_due' => 'nullable|date',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $driver = Driver::find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $driver->update($request->only([
                'user_id',
                'team_name',
                'status',
                'job_assignment',
                'truck_number',
                'rating',
                'license_expiry',
                'vehicle_maintenance_due',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Driver updated successfully',
                'data' => $driver->fresh()->load('user'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validate = Validator::make($request->all(), [
            'status' => 'required|in:active,in-transit,on-break,available',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            $driver = Driver::find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $driver->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Driver status updated successfully',
                'data' => $driver->fresh()->load('user'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update driver status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $driver = Driver::find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $driver->delete();

            return response()->json([
                'success' => true,
                'message' => 'Driver deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAlerts(): JsonResponse
    {
        try {
            $alerts = [];

            // Check for license expiry alerts (expiring within 30 days)
            $licenseExpiring = Driver::with('user')
                ->whereNotNull('license_expiry')
                ->where('license_expiry', '<=', Carbon::now()->addDays(30))
                ->where('license_expiry', '>=', Carbon::now())
                ->get();

            foreach ($licenseExpiring as $driver) {
                $alerts[] = [
                    'type' => 'license_expiry',
                    'severity' => 'warning',
                    'message' => $driver->team_name . ': Driver license expiring soon',
                    'driver_id' => $driver->id,
                    'team_name' => $driver->team_name,
                    'due_date' => $driver->license_expiry,
                ];
            }

            // Check for vehicle maintenance alerts (due within 7 days)
            $maintenanceDue = Driver::with('user')
                ->whereNotNull('vehicle_maintenance_due')
                ->where('vehicle_maintenance_due', '<=', Carbon::now()->addDays(7))
                ->where('vehicle_maintenance_due', '>=', Carbon::now())
                ->get();

            foreach ($maintenanceDue as $driver) {
                $alerts[] = [
                    'type' => 'vehicle_maintenance',
                    'severity' => 'warning',
                    'message' => $driver->team_name . ': Vehicle maintenance due',
                    'driver_id' => $driver->id,
                    'team_name' => $driver->team_name,
                    'due_date' => $driver->vehicle_maintenance_due,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Alerts retrieved successfully',
                'data' => [
                    'total_alerts' => count($alerts),
                    'alerts' => $alerts,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve alerts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPerformance(): JsonResponse
    {
        try {
            $drivers = Driver::with('user')->get();

            $performance = $drivers->map(function ($driver) {
                return [
                    'team_name' => $driver->team_name,
                    'rating' => $driver->rating,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Driver performance retrieved successfully',
                'data' => $performance,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve driver performance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignVehicle(Request $request, $id): JsonResponse
    {
        $validate = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:vehicles,id',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $driver = Driver::find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $vehicle = \App\Models\Vehicle::find($request->vehicle_id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found',
                ], 404);
            }

            // Check if vehicle is available
            if ($vehicle->status !== 'available') {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle is not available for assignment',
                ], 409);
            }

            // Assign vehicle to driver
            $driver->update(['assigned_vehicle_id' => $vehicle->id]);

            // Update vehicle status
            $vehicle->update(['status' => 'in-use']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vehicle assigned successfully',
                'data' => $driver->fresh()->load(['assignedVehicle', 'user']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function unassignVehicle($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $driver = Driver::find($id);

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            if (!$driver->assigned_vehicle_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No vehicle assigned to this driver',
                ], 409);
            }

            // Get the vehicle
            $vehicle = $driver->assignedVehicle;

            // Unassign vehicle from driver
            $driver->update(['assigned_vehicle_id' => null]);

            // Update vehicle status to available
            if ($vehicle) {
                $vehicle->update(['status' => 'available']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vehicle unassigned successfully',
                'data' => $driver->fresh()->load('user'),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
