<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    /**
     * Public: List active menu items.
     */
    public function index(Request $request)
    {
        $location = $request->query('location');

        $query = DB::table('menu_items')->where('is_active', true);

        if ($location) {
            $query->where('location', $location);
        }

        $items = $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();

        return response()->json([
            'data' => $items,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: List all menu items.
     */
    public function adminIndex(Request $request)
    {
        $items = DB::table('menu_items')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: Create menu item.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'url' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'target' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'location' => 'nullable|string|max:50',
        ]);

        $maxOrder = DB::table('menu_items')->max('sort_order') ?? 0;

        $id = DB::table('menu_items')->insertGetId([
            'label' => $validated['label'],
            'url' => $validated['url'],
            'icon' => $validated['icon'] ?? null,
            'target' => $validated['target'] ?? '_self',
            'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
            'is_active' => $validated['is_active'] ?? true,
            'location' => $validated['location'] ?? 'sidebar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = DB::table('menu_items')->where('id', $id)->first();

        return response()->json([
            'data' => $item,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Admin: Update menu item.
     */
    public function update(Request $request, $id)
    {
        $item = DB::table('menu_items')->where('id', $id)->first();
        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Menu item not found']],
            ], 404);
        }

        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:100',
            'url' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'target' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'location' => 'nullable|string|max:50',
        ]);

        $updates = ['updated_at' => now()];
        foreach (['label', 'url', 'icon', 'target', 'sort_order', 'is_active', 'location'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        DB::table('menu_items')->where('id', $id)->update($updates);

        $updated = DB::table('menu_items')->where('id', $id)->first();

        return response()->json([
            'data' => $updated,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: Delete menu item.
     */
    public function destroy($id)
    {
        $item = DB::table('menu_items')->where('id', $id)->first();
        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Menu item not found']],
            ], 404);
        }

        DB::table('menu_items')->where('id', $id)->delete();

        return response()->json([
            'data' => ['success' => true],
            'meta' => null,
            'errors' => null,
        ]);
    }
}

