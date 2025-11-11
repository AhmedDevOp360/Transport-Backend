<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMoveRequestRequest;
use App\Models\MoveRequest;
use App\Models\MoveRequestApplication;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MoveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can view move requests.',
            ], 403);
        }
        try {
            $query = MoveRequest::with('items')->where('status', 'pending');

            // Search filter - searches in move_title, move_type, and description
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('move_title', 'like', "%{$search}%")
                        ->orWhere('move_type', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Location filter - searches in pickup_address and drop_address
            if ($request->has('location') && !empty($request->location)) {
                $location = $request->location;
                $query->where(function ($q) use ($location) {
                    $q->where('pickup_address', 'like', "%{$location}%")
                        ->orWhere('drop_address', 'like', "%{$location}%");
                });
            }

            // Vehicle type filter (move_type)
            if ($request->has('vehicle_type') && !empty($request->vehicle_type)) {
                $query->where('move_type', $request->vehicle_type);
            }

            // Budget range filter
            if ($request->has('budget_min') && !empty($request->budget_min)) {
                $query->where('budget_max', '>=', $request->budget_min);
            }

            if ($request->has('budget_max') && !empty($request->budget_max)) {
                $query->where('budget_min', '<=', $request->budget_max);
            }

            $moveRequests = $query->orderBy('created_at', 'desc')->get();
            $myRequests = MoveRequest::whereHas('applications', function ($q) {
                $q->where('status', 'accepted')->where('user_id', Auth::id());
            })->orderBy('created_at', 'desc');

            $data = [
                'total_requests' => $myRequests->count(),
                'total_pending_requests' => $moveRequests->where('status', 'pending')->count(),
                'total_confirmed_requests' => $myRequests->where('status', 'confirmed')->count(),
                'total_in_progress_requests' => $myRequests->where('status', 'in-progress')->count(),
                'total_completed_requests' => $myRequests->where('status', 'completed')->count(),
                'total_rejected_requests' => $myRequests->where('status', 'rejected')->count(),
                'move_requests' => $moveRequests
            ];

            return response()->json([
                'success' => true,
                'message' => 'Move requests retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve move requests',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only customer can create move requests.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            // 'user_id' => 'required|exists:users,id',
            'move_type' => 'required|string|max:255',
            'vehicle_type' => 'required|string|max:255',
            'move_title' => 'required|string|max:255',
            'pickup_address' => 'required|string',
            'drop_address' => 'required|string',
            'move_date' => 'required|date',
            'move_time' => 'required|date_format:H:i:s',
            'property_size' => 'required|string|max:255',
            'budget_min' => 'required|numeric|min:0',
            'budget_max' => 'required|numeric|min:0|gte:budget_min',
            'estimated_delivery_date' => 'nullable|date|after_or_equal:move_date',
            'description' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
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

            $moveRequest = MoveRequest::create([
                'user_id' => Auth::id(),
                'move_type' => $request->move_type,
                'vehicle_type' => $request->vehicle_type,
                'move_title' => $request->move_title,
                'pickup_address' => $request->pickup_address,
                'drop_address' => $request->drop_address,
                'move_date' => $request->move_date,
                'move_time' => $request->move_time,
                'property_size' => $request->property_size,
                'budget_min' => $request->budget_min,
                'budget_max' => $request->budget_max,
                'estimated_delivery_date' => $request->estimated_delivery_date,
                'description' => $request->description,
            ]);

            foreach ($request->items as $item) {
                $moveRequest->items()->create([
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Move request created successfully',
                'data' => $moveRequest->load('items'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create move request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function apply(Request $request, $id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can apply for move requests.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            // 'user_id' => 'required|exists:users,id',
            'offered_price' => 'required|numeric|min:0',
            'delivery_time' => 'required|string|max:100',
            'message' => 'nullable|string',
            'negotiable' => 'nullable|boolean',
            'services' => 'nullable|array',
            'services.*.service_name' => 'required|string|max:255',
            'services.*.price' => 'required|numeric|min:0',
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

            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if user has already applied
            $existingApplication = MoveRequestApplication::where('move_request_id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if ($existingApplication) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already applied for this move request',
                ], 409);
            }

            // Create the application
            $application = MoveRequestApplication::create([
                'move_request_id' => $id,
                'user_id' => Auth::id(),
                'offered_price' => $request->offered_price,
                'delivery_time' => $request->delivery_time,
                'message' => $request->message,
                'negotiable' => $request->negotiable ?? false,
            ]);

            // Create services if provided
            if ($request->has('services') && is_array($request->services)) {
                foreach ($request->services as $service) {
                    $application->services()->create([
                        'service_name' => $service['service_name'],
                        'price' => $service['price'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Application submitted successfully',
                'data' => $application->load('user', 'moveRequest', 'services'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit application',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateApplicationStatus(Request $request, $id, $application_id): JsonResponse
    {
        $validate = Validator::make($request->all(), [
            'status' => 'required|in:accepted,rejected',
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

            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the authenticated user is the owner of the move request
            if ($moveRequest->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update applications for your own move requests.',
                ], 403);
            }

            // Find the application
            $application = MoveRequestApplication::where('id', $application_id)
                ->where('move_request_id', $id)
                ->first();

            if (!$application) {
                return response()->json([
                    'success' => false,
                    'message' => 'Application not found',
                ], 404);
            }

            if ($application->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Application status has already been updated',
                ], 409);
            }

            $status = $request->status;

            if ($status === 'accepted') {
                if (Auth::user()->user_type !== 'customer') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized. Only customer can accept applications.',
                    ], 403);
                }
                // Update the application to accepted
                $application->update(['status' => 'accepted']);

                // Reject all other pending applications for this move request
                MoveRequestApplication::where('move_request_id', $id)
                    ->where('id', '!=', $application_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected']);

                // Update the move request status to confirmed
                $moveRequest->update(['status' => 'confirmed']);

                $message = 'Application accepted successfully. All other pending applications have been rejected.';
            } else {
                // Only update this application to rejected
                $application->update(['status' => 'rejected']);

                $message = 'Application rejected successfully.';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $application->fresh()->load('user', 'moveRequest', 'services'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update application status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function listApplications($id): JsonResponse
    {
        if (Auth::user()->user_type !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only customers can view applications for their move requests.',
            ], 403);
        }
        try {
            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the authenticated user is the owner of the move request
            if ($moveRequest->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only view applications for your own move requests.',
                ], 403);
            }

            $hasPending = $moveRequest->applications()->where('status', 'pending')->exists();

            if (!$hasPending) {
                return response()->json([
                    'success' => false,
                    'message' => 'This move request is already assigned.',
                ], 403);
            }

            // Get all applications for this move request
            $applications = MoveRequestApplication::where('move_request_id', $id)
                ->with(['user', 'services'])
                ->orderBy('created_at', 'desc')
                ->get();

            $data = [
                'total_applications' => $applications->count(),
                'total_pending' => $applications->where('status', 'pending')->count(),
                'total_accepted' => $applications->where('status', 'accepted')->count(),
                'total_rejected' => $applications->where('status', 'rejected')->count(),
                'applications' => $applications,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Applications retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve applications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function viewApplication($id, $application_id): JsonResponse
    {
        try {
            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the authenticated user is the owner of the move request
            if ($moveRequest->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only view applications for your own move requests.',
                ], 403);
            }

            // Find the application
            $application = MoveRequestApplication::where('id', $application_id)
                ->where('move_request_id', $id)
                ->with(['user', 'moveRequest', 'services'])
                ->first();

            if (!$application) {
                return response()->json([
                    'success' => false,
                    'message' => 'Application not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Application retrieved successfully',
                'data' => $application,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve application',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateApplication(Request $request, $id, $application_id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can update applications.',
            ], 403);
        }

        $validate = Validator::make($request->all(), [
            'offered_price' => 'nullable|numeric|min:0',
            'delivery_time' => 'nullable|string|max:100',
            'message' => 'nullable|string',
            'negotiable' => 'nullable|boolean',
            'services' => 'nullable|array',
            'services.*.id' => 'nullable|exists:move_request_application_services,id',
            'services.*.service_name' => 'required|string|max:255',
            'services.*.price' => 'required|numeric|min:0',
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

            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Find the application
            $application = MoveRequestApplication::where('id', $application_id)
                ->where('move_request_id', $id)
                ->first();

            if (!$application) {
                return response()->json([
                    'success' => false,
                    'message' => 'Application not found',
                ], 404);
            }

            // Check if the authenticated user is the owner of the application
            if ($application->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update your own applications.',
                ], 403);
            }

            // Only allow updates if the application is still pending
            if ($application->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update application. Application has already been ' . $application->status . '.',
                ], 409);
            }

            // Update application details
            $application->update([
                'offered_price' => $request->offered_price ?? $application->offered_price,
                'delivery_time' => $request->delivery_time ?? $application->delivery_time,
                'message' => $request->message ?? $application->message,
                'negotiable' => $request->negotiable ?? $application->negotiable,
            ]);

            // Update services if provided
            if ($request->has('services') && is_array($request->services)) {
                // Track which services to keep
                $serviceIdsToKeep = [];

                foreach ($request->services as $serviceData) {
                    if (isset($serviceData['id'])) {
                        // Update existing service
                        $service = $application->services()->find($serviceData['id']);
                        if ($service) {
                            $service->update([
                                'service_name' => $serviceData['service_name'],
                                'price' => $serviceData['price'],
                            ]);
                            $serviceIdsToKeep[] = $service->id;
                        }
                    } else {
                        // Create new service
                        $newService = $application->services()->create([
                            'service_name' => $serviceData['service_name'],
                            'price' => $serviceData['price'],
                        ]);
                        $serviceIdsToKeep[] = $newService->id;
                    }
                }

                // Delete services that are no longer in the list
                $application->services()->whereNotIn('id', $serviceIdsToKeep)->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Application updated successfully',
                'data' => $application->fresh()->load('user', 'moveRequest', 'services'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update application',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function applicationDetail($id, $application_id): JsonResponse
    {
        if (Auth::user()->user_type !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only customers can view application details.',
            ], 403);
        }

        try {
            // Check if move request exists
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the authenticated user is the owner of the move request
            if ($moveRequest->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only view applications for your own move requests.',
                ], 403);
            }

            // Find the application with all relationships
            $application = MoveRequestApplication::where('id', $application_id)
                ->where('move_request_id', $id)
                ->with(['user', 'services'])
                ->first();

            if (!$application) {
                return response()->json([
                    'success' => false,
                    'message' => 'Application not found',
                ], 404);
            }

            // Load move request with items
            $moveRequest->load('items');

            // Calculate cost breakdown
            $servicesTotal = $application->services->sum('price');
            $totalCost = $application->offered_price + $servicesTotal;

            $data = [
                'quote_id' => $application->id,
                'provider' => [
                    'id' => $application->user->id,
                    'name' => $application->user->name,
                    'email' => $application->user->email,
                    'phone' => $application->user->phone,
                    'profile_image' => $application->user->profile_image,
                    'address' => $application->user->address,
                    'country' => $application->user->country,
                ],
                'quote_details' => [
                    'offered_price' => $application->offered_price,
                    'delivery_time' => $application->delivery_time,
                    'message' => $application->message,
                    'negotiable' => $application->negotiable,
                    'status' => $application->status,
                    'applied_at' => $application->created_at,
                    'updated_at' => $application->updated_at,
                ],
                'included_services' => $application->services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'service_name' => $service->service_name,
                        'price' => $service->price,
                    ];
                }),
                'move_details' => [
                    'move_title' => $moveRequest->move_title,
                    'move_type' => $moveRequest->move_type,
                    'vehicle_type' => $moveRequest->vehicle_type,
                    'pickup_address' => $moveRequest->pickup_address,
                    'drop_address' => $moveRequest->drop_address,
                    'move_date' => $moveRequest->move_date,
                    'move_time' => $moveRequest->move_time,
                    'property_size' => $moveRequest->property_size,
                    'estimated_delivery_date' => $moveRequest->estimated_delivery_date,
                    'description' => $moveRequest->description,
                ],
                'items_to_move' => $moveRequest->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_name' => $item->item_name,
                        'quantity' => $item->quantity,
                        'notes' => $item->notes,
                    ];
                }),
                'cost_breakdown' => [
                    'base_price' => $application->offered_price,
                    'services' => $application->services->map(function ($service) {
                        return [
                            'service_name' => $service->service_name,
                            'price' => $service->price,
                        ];
                    }),
                    'services_total' => $servicesTotal,
                    'total_cost' => $totalCost,
                ],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Application details retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve application details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activeJobs(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can view active jobs.',
            ], 403);
        }

        try {
            // Get all accepted applications for the current provider
            $query = MoveRequestApplication::where('user_id', Auth::id())
                ->where('status', 'accepted')
                ->with(['moveRequest.items', 'moveRequest.user', 'services']);

            // Filter by move request status
            if ($request->has('status') && !empty($request->status)) {
                $query->whereHas('moveRequest', function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
            }

            // Search by job ID (move request ID)
            if ($request->has('job_id') && !empty($request->job_id)) {
                $query->whereHas('moveRequest', function ($q) use ($request) {
                    $q->where('id', $request->job_id);
                });
            }

            // Search by customer name
            if ($request->has('customer_name') && !empty($request->customer_name)) {
                $customerName = $request->customer_name;
                $query->whereHas('moveRequest.user', function ($q) use ($customerName) {
                    $q->where('name', 'like', "%{$customerName}%");
                });
            }

            $applications = $query->orderBy('created_at', 'desc')->get();

            // Get all move requests for this provider (accepted applications)
            $allMoveRequests = $applications->pluck('moveRequest');

            // Calculate statistics
            $totalActiveJobs = $allMoveRequests->whereNotIn('status', ['completed', 'rejected'])->count();
            $completedToday = $allMoveRequests->where('status', 'completed')
                ->where('updated_at', '>=', now()->startOfDay())
                ->count();
            $pendingJobs = $allMoveRequests->where('status', 'pending')->count();
            $urgentJobs = $allMoveRequests->where('move_date', '<=', now()->addDays(2))
                ->whereNotIn('status', ['completed', 'rejected'])
                ->count();

            // Job distribution
            $jobDistribution = [
                'confirmed' => $allMoveRequests->where('status', 'confirmed')->count(),
                'pending' => $allMoveRequests->where('status', 'pending')->count(),
                'in_progress' => $allMoveRequests->where('status', 'in-progress')->count(),
                'completed' => $allMoveRequests->where('status', 'completed')->count(),
            ];

            // Format job cards
            $jobs = $applications->map(function ($application) {
                $moveRequest = $application->moveRequest;
                $customer = $moveRequest->user;

                return [
                    'job_id' => 'JOB-ID: #MV-' . $moveRequest->created_at->format('Y') . '-' . str_pad($moveRequest->id, 3, '0', STR_PAD_LEFT),
                    'move_request_id' => $moveRequest->id,
                    'application_id' => $application->id,
                    'status' => $moveRequest->status,
                    'move_date' => $moveRequest->move_date,
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'profile_image' => $customer->profile_image,
                    ],
                    'pickup_location' => $moveRequest->pickup_address,
                    'drop_location' => $moveRequest->drop_address,
                    'move_time' => $moveRequest->move_time,
                    'vehicle_type' => $moveRequest->vehicle_type,
                    'offered_price' => $application->offered_price,
                    'delivery_time' => $application->delivery_time,
                    'created_at' => $moveRequest->created_at,
                ];
            });

            $data = [
                'statistics' => [
                    'total_active_jobs' => $totalActiveJobs,
                    'completed_today' => $completedToday,
                    'pending_jobs' => $pendingJobs,
                    'urgent_jobs' => $urgentJobs,
                ],
                'job_distribution' => $jobDistribution,
                'jobs' => $jobs,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Active jobs retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active jobs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateMoveRequestStatus(Request $request, $id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can update move request status.',
            ], 403);
        }
        $validate = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,in-progress,completed,rejected',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validate->errors(),
            ], 422);
        }

        try {
            // Find the move request
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the authenticated user has an accepted application for this move request
            $acceptedApplication = MoveRequestApplication::where('move_request_id', $id)
                ->where('user_id', Auth::id())
                ->where('status', 'accepted')
                ->first();

            if (!$acceptedApplication) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update move requests where your application has been accepted.',
                ], 403);
            }

            // Update the move request status
            $moveRequest->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Move request status updated successfully',
                'data' => $moveRequest->fresh()->load('items'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update move request status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignDriver(Request $request, $id): JsonResponse
    {
        if (Auth::user()->user_type !== 'provider') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only providers can assign drivers to move requests.',
            ], 403);
        }

        $validate = Validator::make($request->all(), [
            'driver_id' => 'required|exists:drivers,id',
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

            // Find the move request
            $moveRequest = MoveRequest::find($id);

            if (!$moveRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Move request not found',
                ], 404);
            }

            // Check if the move request is confirmed
            if ($moveRequest->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot assign driver. Move request must be in confirmed status.',
                ], 400);
            }

            // Check if the authenticated provider has an accepted application for this move request
            $acceptedApplication = MoveRequestApplication::where('move_request_id', $id)
                ->where('user_id', Auth::id())
                ->where('status', 'accepted')
                ->first();

            if (!$acceptedApplication) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only assign drivers to move requests where your application has been accepted.',
                ], 403);
            }

            // Verify that the driver belongs to the authenticated provider
            $driver = Driver::where('id', $request->driver_id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found or does not belong to your organization.',
                ], 404);
            }

            // Check if driver is available
            if ($driver->status !== 'available') {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver is not available. Current status: ' . $driver->status,
                ], 400);
            }

            // Check if a driver is already assigned
            if ($moveRequest->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A driver has already been assigned to this move request.',
                ], 409);
            }

            // Assign the driver to the move request
            $moveRequest->update([
                'driver_id' => $driver->id,
                'status' => 'in-progress'
            ]);

            // Update driver status
            $driver->update([
                'status' => 'in-transit',
                'job_assignment' => 'JOB-ID: #MV-' . $moveRequest->created_at->format('Y') . '-' . str_pad($moveRequest->id, 3, '0', STR_PAD_LEFT)
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Driver assigned successfully. Move request status updated to in-progress.',
                'data' => [
                    'move_request' => $moveRequest->fresh()->load(['items', 'driver', 'user']),
                    'driver' => $driver->fresh(),
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activeMoves(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only customers can view their active moves.',
            ], 403);
        }

        try {
            // Single optimized query to get all necessary data
            $allMoves = MoveRequest::where('user_id', Auth::id())
                ->whereHas('applications', function ($q) {
                    $q->where('status', 'accepted');
                })
                ->with(['applications' => function ($q) {
                    $q->where('status', 'accepted')->select('id', 'move_request_id', 'user_id', 'offered_price', 'delivery_time', 'created_at')
                        ->with(['user:id,name,email,phone,profile_image']);
                }])
                ->whereNotIn('status', ['rejected'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Use Carbon for date handling
            $now = \Carbon\Carbon::now();
            $thisMonthStart = $now->copy()->startOfMonth();
            $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
            $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

            // Separate moves by time periods
            $currentMonthMoves = $allMoves->filter(function ($move) use ($thisMonthStart) {
                return \Carbon\Carbon::parse($move->created_at)->gte($thisMonthStart);
            });

            $lastMonthMoves = $allMoves->filter(function ($move) use ($lastMonthStart, $lastMonthEnd) {
                $createdAt = \Carbon\Carbon::parse($move->created_at);
                return $createdAt->gte($lastMonthStart) && $createdAt->lte($lastMonthEnd);
            });

            // Calculate statistics from the single dataset
            $totalActiveMoves = $allMoves->whereNotIn('status', ['completed'])->count();
            $inProgressCount = $allMoves->where('status', 'in-progress')->count();
            $completedThisMonth = $currentMonthMoves->where('status', 'completed')->count();

            // Total spent calculation
            $totalSpent = $allMoves->sum(function ($move) {
                $acceptedApp = $move->applications->first();
                return $acceptedApp ? $acceptedApp->offered_price : 0;
            });

            // Last month statistics
            $lastMonthActiveMoves = $lastMonthMoves->whereNotIn('status', ['completed'])->count();
            $lastMonthInProgress = $lastMonthMoves->where('status', 'in-progress')->count();
            $lastMonthCompleted = $lastMonthMoves->where('status', 'completed')->count();
            $lastMonthSpent = $lastMonthMoves->sum(function ($move) {
                $acceptedApp = $move->applications->first();
                return $acceptedApp ? $acceptedApp->offered_price : 0;
            });

            // Calculate changes
            $activeMoveChange = $lastMonthActiveMoves > 0
                ? round((($totalActiveMoves - $lastMonthActiveMoves) / $lastMonthActiveMoves) * 100, 1)
                : 0;
            $inProgressChange = $lastMonthInProgress > 0
                ? round((($inProgressCount - $lastMonthInProgress) / $lastMonthInProgress) * 100, 1)
                : 0;
            $completedChange = $lastMonthCompleted > 0
                ? round((($completedThisMonth - $lastMonthCompleted) / $lastMonthCompleted) * 100, 1)
                : 0;
            $spentChange = $lastMonthSpent > 0
                ? round((($totalSpent - $lastMonthSpent) / $lastMonthSpent) * 100, 1)
                : 0;

            // Apply filters to display results
            $filteredMoves = $allMoves;

            if ($request->has('status') && !empty($request->status)) {
                $status = $request->status;
                if ($status === 'awaiting_pickup') {
                    $filteredMoves = $allMoves->where('status', 'confirmed');
                } elseif ($status === 'in_progress') {
                    $filteredMoves = $allMoves->where('status', 'in-progress');
                } elseif ($status === 'completed') {
                    $filteredMoves = $allMoves->where('status', 'completed');
                }
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = strtolower($request->search);
                $filteredMoves = $filteredMoves->filter(function ($move) use ($search) {
                    return stripos($move->move_title, $search) !== false ||
                           stripos($move->move_type, $search) !== false ||
                           stripos($move->pickup_address, $search) !== false ||
                           stripos($move->drop_address, $search) !== false;
                });
            }

            // Format move cards
            $moveCards = $filteredMoves->map(function ($move) {
                $acceptedApplication = $move->applications->first();
                $provider = $acceptedApplication ? $acceptedApplication->user : null;

                // Calculate progress
                $progress = match ($move->status) {
                    'confirmed' => 25,
                    'in-progress' => 60,
                    'completed' => 100,
                    default => 0
                };

                // Map status
                $displayStatus = match ($move->status) {
                    'confirmed' => 'awaiting_pickup',
                    'in-progress' => 'in_progress',
                    'completed' => 'delivered',
                    default => $move->status
                };

                return [
                    'move_id' => '#MOV-' . \Carbon\Carbon::parse($move->created_at)->format('Y') . '-' . str_pad($move->id, 4, '0', STR_PAD_LEFT),
                    'move_request_id' => $move->id,
                    'move_title' => $move->move_title,
                    'status' => $displayStatus,
                    'provider' => $provider ? [
                        'name' => $provider->name,
                        'profile_image' => $provider->profile_image,
                        'rating' => 4.5,
                    ] : null,
                    'route' => [
                        'from' => $move->pickup_address,
                        'to' => $move->drop_address,
                    ],
                    'scheduled_date' => $move->move_date,
                    'delivery_date' => $move->estimated_delivery_date,
                    'progress' => $progress,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Active moves retrieved successfully',
                'data' => [
                    'statistics' => [
                        'total_active_moves' => [
                            'count' => $totalActiveMoves,
                            'change_from_last_month' => $activeMoveChange,
                        ],
                        'in_progress' => [
                            'count' => $inProgressCount,
                            'change_this_week' => $inProgressChange,
                        ],
                        'completed_this_month' => [
                            'count' => $completedThisMonth,
                            'change_from_last_month' => $completedChange,
                        ],
                        'total_spent' => [
                            'amount' => $totalSpent,
                            'change_from_last_month' => $spentChange,
                        ],
                    ],
                    'moves' => $moveCards,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active moves',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function moveHistory(Request $request): JsonResponse
    {
        if (Auth::user()->user_type !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only customers can view their move history.',
            ], 403);
        }

        try {
            // Single optimized query - get completed, cancelled, and archived moves
            $query = MoveRequest::where('user_id', Auth::id())
                ->whereHas('applications', function ($q) {
                    $q->where('status', 'accepted');
                })
                ->with(['applications' => function ($q) {
                    $q->where('status', 'accepted')->select('id', 'move_request_id', 'user_id', 'offered_price')
                        ->with(['user:id,name']);
                }])
                ->whereIn('status', ['completed', 'rejected'])
                ->select('id', 'user_id', 'move_title', 'move_type', 'pickup_address', 'drop_address', 'move_date', 'status', 'created_at', 'updated_at');

            // Filter by move type
            if ($request->has('move_type') && $request->move_type !== 'all') {
                $query->where('move_type', $request->move_type);
            }

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                if ($request->status === 'completed') {
                    $query->where('status', 'completed');
                } elseif ($request->status === 'cancelled') {
                    $query->where('status', 'rejected');
                }
            }

            // Sort
            $sortBy = $request->get('sort', 'recent');
            if ($sortBy === 'recent') {
                $query->orderBy('updated_at', 'desc');
            } elseif ($sortBy === 'oldest') {
                $query->orderBy('updated_at', 'asc');
            }

            // Pagination
            $perPage = $request->get('per_page', 10);
            $allHistory = $query->paginate($perPage);

            // Calculate statistics (from all history, not just current page)
            $allMoves = MoveRequest::where('user_id', Auth::id())
                ->whereHas('applications', function ($q) {
                    $q->where('status', 'accepted');
                })
                ->with(['applications' => function ($q) {
                    $q->where('status', 'accepted')->select('id', 'move_request_id', 'offered_price');
                }])
                ->select('id', 'status', 'created_at')
                ->get();

            $totalCompleted = $allMoves->where('status', 'completed')->count();
            $totalCancelled = $allMoves->where('status', 'rejected')->count();
            $totalSpent = $allMoves->where('status', 'completed')->sum(function ($move) {
                $acceptedApp = $move->applications->first();
                return $acceptedApp ? $acceptedApp->offered_price : 0;
            });

            // Calculate average rating (placeholder - you would calculate from a ratings table)
            $averageRating = 4.5;

            // Format history items
            $historyItems = $allHistory->map(function ($move) {
                $acceptedApplication = $move->applications->first();
                $provider = $acceptedApplication ? $acceptedApplication->user : null;

                return [
                    'move_id' => '#MOV-' . str_pad($move->id, 4, '0', STR_PAD_LEFT),
                    'move_request_id' => $move->id,
                    'type' => $move->move_title,
                    'provider' => $provider ? $provider->name : 'N/A',
                    'route' => $move->pickup_address . ' → ' . $move->drop_address,
                    'date' => \Carbon\Carbon::parse($move->updated_at)->format('d M Y'),
                    'cost' => $acceptedApplication ? $acceptedApplication->offered_price : 0,
                    'status' => match ($move->status) {
                        'completed' => 'completed',
                        'rejected' => 'cancelled',
                        default => 'archived'
                    },
                    'rating' => $move->status === 'completed' ? 4.5 : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Move history retrieved successfully',
                'data' => [
                    'statistics' => [
                        'total_completed' => $totalCompleted,
                        'total_spent' => $totalSpent,
                        'cancelled_moves' => $totalCancelled,
                        'average_rating' => $averageRating,
                    ],
                    'history' => $historyItems,
                    'pagination' => [
                        'current_page' => $allHistory->currentPage(),
                        'total_pages' => $allHistory->lastPage(),
                        'per_page' => $allHistory->perPage(),
                        'total' => $allHistory->total(),
                        'from' => $allHistory->firstItem(),
                        'to' => $allHistory->lastItem(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve move history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
