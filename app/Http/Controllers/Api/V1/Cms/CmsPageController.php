<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CmsPageController extends Controller
{
    /**
     * Ensure core 6 platform pages exist.
     */
    private function ensureCorePagesExist(): void
    {
        $corePages = [
            ['slug' => 'about', 'title' => 'About Us', 'content' => '<h2>About Yeewala</h2><p>Yeewala is an educational video learning portal built to provide students, software engineers, and technical creators with direct access to high-impact course lectures and bite-sized concept tutorials.</p>'],
            ['slug' => 'contact', 'title' => 'Contact Details', 'content' => '<h2>Contact Yeewala Support</h2><p>For educational inquiries, support, or partnership details, please contact us at support@yeewala.com or call our educational hotline.</p>'],
            ['slug' => 'terms', 'title' => 'Terms & Conditions', 'content' => '<h2>Terms and Conditions of Use</h2><p>By accessing Yeewala, students and educators agree to adhere to standard educational guidelines, respectful interaction, and authorized learning content access.</p>'],
            ['slug' => 'privacy', 'title' => 'Privacy Policy', 'content' => '<h2>Privacy Policy</h2><p>Yeewala respects your privacy. All user profile data, watch history, and learning progress are safeguarded with industry-standard encryption.</p>'],
            ['slug' => 'disclaimer', 'title' => 'Disclaimer', 'content' => '<h2>Academic Disclaimer</h2><p>All videos, courses, and educational materials provided on Yeewala are intended solely for learning and personal development purposes.</p>'],
            ['slug' => 'copyrights', 'title' => 'Copyrights', 'content' => '<h2>Copyright & Ownership</h2><p>Yeewala enforces strict intellectual property guidelines. All educational course creators maintain ownership of their content with full academic permission.</p>'],
        ];

        foreach ($corePages as $core) {
            $exists = DB::table('cms_pages')->where('slug', $core['slug'])->exists();
            if (!$exists) {
                DB::table('cms_pages')->insert([
                    'slug' => $core['slug'],
                    'title' => $core['title'],
                    'content' => $core['content'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Public: List all active CMS pages.
     */
    public function index()
    {
        $this->ensureCorePagesExist();
        $pages = DB::table('cms_pages')
            ->where('is_active', true)
            ->select('id', 'slug', 'title', 'meta_description', 'created_at', 'updated_at')
            ->orderBy('title', 'asc')
            ->get();

        return response()->json([
            'data' => $pages,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Public: Get a single CMS page by slug.
     */
    public function show($slug)
    {
        $page = DB::table('cms_pages')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$page) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Page not found']],
            ], 404);
        }

        return response()->json([
            'data' => $page,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: List all pages.
     */
    public function adminIndex()
    {
        $this->ensureCorePagesExist();
        $pages = DB::table('cms_pages')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'data' => $pages,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: Get a single page by ID.
     */
    public function adminShow($id)
    {
        $page = DB::table('cms_pages')->where('id', $id)->first();

        if (!$page) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Page not found']],
            ], 404);
        }

        return response()->json([
            'data' => $page,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: Create a new page.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:cms_pages,slug',
            'content' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);

        $id = DB::table('cms_pages')->insertGetId([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'] ?? '',
            'meta_description' => $validated['meta_description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = DB::table('cms_pages')->where('id', $id)->first();

        return response()->json([
            'data' => $page,
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * Admin: Update page details and content.
     */
    public function update(Request $request, $id)
    {
        $page = DB::table('cms_pages')->where('id', $id)->first();
        if (!$page) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Page not found']],
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'slug' => "sometimes|required|string|max:255|unique:cms_pages,slug,{$id}",
            'content' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $updates = ['updated_at' => now()];

        if (array_key_exists('title', $validated)) {
            $updates['title'] = $validated['title'];
        }
        if (array_key_exists('slug', $validated)) {
            $updates['slug'] = Str::slug($validated['slug']);
        }
        if (array_key_exists('content', $validated)) {
            $updates['content'] = $validated['content'];
        }
        if (array_key_exists('meta_description', $validated)) {
            $updates['meta_description'] = $validated['meta_description'];
        }
        if (array_key_exists('is_active', $validated)) {
            $updates['is_active'] = (bool)$validated['is_active'];
        }

        DB::table('cms_pages')->where('id', $id)->update($updates);

        $updated = DB::table('cms_pages')->where('id', $id)->first();

        return response()->json([
            'data' => $updated,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin: Delete a page.
     */
    public function destroy($id)
    {
        $page = DB::table('cms_pages')->where('id', $id)->first();
        if (!$page) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Page not found']],
            ], 404);
        }

        DB::table('cms_pages')->where('id', $id)->delete();

        return response()->json([
            'data' => ['success' => true],
            'meta' => null,
            'errors' => null,
        ]);
    }
}

