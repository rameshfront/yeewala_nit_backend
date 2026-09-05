<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminLocationController extends Controller
{
    // ==========================================
    // COUNTRIES CRUD
    // ==========================================

    /**
     * List all countries with optional search, status filtering, and state counts.
     */
    public function listCountries(Request $request): JsonResponse
    {
        $query = Country::query()->withCount('states');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('phone_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->query('status') !== 'all') {
            $isActive = filter_var($request->query('status'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $countries = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $countries,
            'meta' => [
                'total' => $countries->count(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a new country.
     */
    public function storeCountry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'code'       => 'nullable|string|max:10|unique:countries,code',
            'phone_code' => 'nullable|string|max:10',
            'is_active'  => 'nullable|boolean',
        ]);

        $country = Country::create([
            'name'       => trim($validated['name']),
            'code'       => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'phone_code' => !empty($validated['phone_code']) ? trim($validated['phone_code']) : null,
            'is_active'  => $validated['is_active'] ?? true,
        ]);

        $country->loadCount('states');

        return response()->json([
            'data' => $country,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Show country details.
     */
    public function showCountry(int $id): JsonResponse
    {
        $country = Country::withCount('states')->findOrFail($id);

        return response()->json([
            'data' => $country,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update an existing country.
     */
    public function updateCountry(Request $request, int $id): JsonResponse
    {
        $country = Country::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:100',
            'code'       => ['nullable', 'string', 'max:10', Rule::unique('countries', 'code')->ignore($country->id)],
            'phone_code' => 'nullable|string|max:10',
            'is_active'  => 'nullable|boolean',
        ]);

        if (array_key_exists('name', $validated)) {
            $country->name = trim($validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $country->code = !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null;
        }
        if (array_key_exists('phone_code', $validated)) {
            $country->phone_code = !empty($validated['phone_code']) ? trim($validated['phone_code']) : null;
        }
        if (array_key_exists('is_active', $validated)) {
            $country->is_active = (bool)$validated['is_active'];
        }

        $country->save();
        $country->loadCount('states');

        return response()->json([
            'data' => $country,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Delete a country.
     */
    public function destroyCountry(int $id): JsonResponse
    {
        $country = Country::findOrFail($id);
        $country->delete();

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null,
        ]);
    }

    // ==========================================
    // STATES CRUD
    // ==========================================

    /**
     * List all states with optional country_id filter, search, and status.
     */
    public function listStates(Request $request): JsonResponse
    {
        $query = State::query()->with('country:id,name,code');

        if ($countryId = $request->query('country_id')) {
            $query->where('country_id', (int)$countryId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->query('status') !== 'all') {
            $isActive = filter_var($request->query('status'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $states = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $states,
            'meta' => [
                'total' => $states->count(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a new state.
     */
    public function storeState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'name'       => 'required|string|max:100',
            'code'       => 'nullable|string|max:10',
            'is_active'  => 'nullable|boolean',
        ]);

        $state = State::create([
            'country_id' => $validated['country_id'],
            'name'       => trim($validated['name']),
            'code'       => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'is_active'  => $validated['is_active'] ?? true,
        ]);

        $state->load('country:id,name,code');

        return response()->json([
            'data' => $state,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Show state details.
     */
    public function showState(int $id): JsonResponse
    {
        $state = State::with('country:id,name,code')->findOrFail($id);

        return response()->json([
            'data' => $state,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update an existing state.
     */
    public function updateState(Request $request, int $id): JsonResponse
    {
        $state = State::findOrFail($id);

        $validated = $request->validate([
            'country_id' => 'sometimes|required|integer|exists:countries,id',
            'name'       => 'sometimes|required|string|max:100',
            'code'       => 'nullable|string|max:10',
            'is_active'  => 'nullable|boolean',
        ]);

        if (array_key_exists('country_id', $validated)) {
            $state->country_id = $validated['country_id'];
        }
        if (array_key_exists('name', $validated)) {
            $state->name = trim($validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $state->code = !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null;
        }
        if (array_key_exists('is_active', $validated)) {
            $state->is_active = (bool)$validated['is_active'];
        }

        $state->save();
        $state->load('country:id,name,code');

        return response()->json([
            'data' => $state,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Delete a state.
     */
    public function destroyState(int $id): JsonResponse
    {
        $state = State::findOrFail($id);
        $state->delete();

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null,
        ]);
    }
}
