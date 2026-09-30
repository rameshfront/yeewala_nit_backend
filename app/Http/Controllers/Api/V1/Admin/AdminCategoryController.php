<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminCategoryController extends Controller
{
    /**
     * List all categories with optional search, status filtering, parent hierarchy, and counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()
            ->with(['parent:id,name,slug'])
            ->withCount(['children', 'videos']);

        // Search by name or slug
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Status filter: active, inactive, or all
        if ($request->has('status') && $request->query('status') !== 'all') {
            $status = $request->query('status');
            if ($status === 'active' || $status === '1' || $status === 'true') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive' || $status === '0' || $status === 'false') {
                $query->where('is_active', false);
            }
        }

        // Parent filter: root (only parent categories) or by parent_id
        if ($request->has('parent_id')) {
            $parentId = $request->query('parent_id');
            if ($parentId === 'null' || $parentId === 'root') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', (int)$parentId);
            }
        }

        $sort = $request->query('sort', 'name');
        $order = strtolower($request->query('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['name', 'created_at', 'videos_count', 'children_count'])) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderBy('name', 'asc');
        }

        $categories = $query->get();

        return response()->json([
            'data' => $categories,
            'meta' => [
                'total' => $categories->count(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'slug'      => 'nullable|string|max:120|unique:categories,slug',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
        ]);

        $name = trim($validated['name']);
        
        // Auto-generate slug if omitted
        $slug = !empty($validated['slug']) 
            ? Str::slug($validated['slug']) 
            : Str::slug($name);

        // Ensure slug uniqueness
        $baseSlug = $slug;
        $count = 1;
        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$count}";
            $count++;
        }

        $category = Category::create([
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => !empty($validated['parent_id']) ? (int)$validated['parent_id'] : null,
            'is_active' => isset($validated['is_active']) ? (bool)$validated['is_active'] : true,
        ]);

        $category->load(['parent:id,name,slug']);
        $category->loadCount(['children', 'videos']);

        return response()->json([
            'data' => $category,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Show single category details.
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::with(['parent:id,name,slug', 'children'])
            ->withCount(['children', 'videos'])
            ->findOrFail($id);

        return response()->json([
            'data' => $category,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|required|string|max:100',
            'slug'      => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('categories', 'slug')->ignore($category->id),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                Rule::notIn([$category->id]), // Cannot be parent of itself
            ],
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('name', $validated)) {
            $category->name = trim($validated['name']);
        }

        if (array_key_exists('slug', $validated)) {
            $category->slug = !empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($category->name);
        }

        if (array_key_exists('parent_id', $validated)) {
            $category->parent_id = !empty($validated['parent_id']) ? (int)$validated['parent_id'] : null;
        }

        if (array_key_exists('is_active', $validated)) {
            $category->is_active = (bool)$validated['is_active'];
        }

        $category->save();
        $category->load(['parent:id,name,slug']);
        $category->loadCount(['children', 'videos']);

        return response()->json([
            'data' => $category,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->is_active = !$category->is_active;
        $category->save();

        $category->load(['parent:id,name,slug']);
        $category->loadCount(['children', 'videos']);

        return response()->json([
            'data' => $category,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Delete a category.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        // Detach child categories so they don't break
        Category::where('parent_id', $category->id)->update(['parent_id' => null]);

        $category->delete();

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null,
        ]);
    }
}
