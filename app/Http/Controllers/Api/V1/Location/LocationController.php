<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Get list of active countries for public selection (e.g. registration, profile).
     */
    public function countries(Request $request): JsonResponse
    {
        $countries = Country::query()
            ->active()
            ->select(['id', 'name', 'code', 'phone_code'])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $countries,
            'meta' => [
                'total' => $countries->count(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Get list of active states for a given country.
     */
    public function states(Request $request, int $countryId): JsonResponse
    {
        $states = State::query()
            ->where('country_id', $countryId)
            ->active()
            ->select(['id', 'country_id', 'name', 'code'])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $states,
            'meta' => [
                'country_id' => $countryId,
                'total' => $states->count(),
            ],
            'errors' => null,
        ]);
    }
}
