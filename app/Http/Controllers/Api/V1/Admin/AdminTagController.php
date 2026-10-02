<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminTagController extends Controller
{
    /**
     * List all tags with optional search and video counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query()->withCount('videos');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $sort = $request->query('sort', 'name');
        $order = strtolower($request->query('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['name', 'created_at', 'videos_count'])) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderBy('name', 'asc');
        }

        $tags = $query->get();

        return response()->json([
            'data' => $tags,
            'meta' => [
                'total' => $tags->count(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a new tag.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80|unique:tags,name',
            'slug' => 'nullable|string|max:100|unique:tags,slug',
        ]);

        $name = trim($validated['name']);
        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($name);

        // Ensure slug uniqueness
        $baseSlug = $slug;
        $count = 1;
        while (Tag::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$count}";
            $count++;
        }

        $tag = Tag::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        $tag->loadCount('videos');

        return response()->json([
            'data' => $tag,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Show single tag details.
     */
    public function show(int $id): JsonResponse
    {
        $tag = Tag::withCount('videos')->findOrFail($id);

        return response()->json([
            'data' => $tag,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update an existing tag.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tag = Tag::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                Rule::unique('tags', 'name')->ignore($tag->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('tags', 'slug')->ignore($tag->id),
            ],
        ]);

        if (array_key_exists('name', $validated)) {
            $tag->name = trim($validated['name']);
        }

        if (array_key_exists('slug', $validated)) {
            $tag->slug = !empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($tag->name);
        }

        $tag->save();
        $tag->loadCount('videos');

        return response()->json([
            'data' => $tag,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Delete a tag.
     */
    public function destroy(int $id): JsonResponse
    {
        $tag = Tag::findOrFail($id);
        $tag->delete();

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null,
        ]);
    }
}
