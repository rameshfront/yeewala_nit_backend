<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    /**
     * Cast raw database setting value to proper PHP/JSON type.
     */
    private function castValue($value, string $type)
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => (bool)$value && $value !== '0' && $value !== 'false',
            'integer' => (int)$value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => (string)$value,
        };
    }

    /**
     * Get all settings grouped by group name.
     */
    public function index()
    {
        $rows = DB::table('settings')->get();
        $grouped = [];

        foreach ($rows as $row) {
            $group = $row->group;
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][$row->key] = $this->castValue($row->value, $row->type ?? 'string');
        }

        return response()->json([
            'data' => $grouped,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update settings for a specific group.
     */
    public function update(Request $request, string $group)
    {
        $values = $request->input('values', []);
        if (!is_array($values)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'INVALID_INPUT', 'message' => 'Values must be an object/array']],
            ], 422);
        }

        foreach ($values as $key => $val) {
            $existing = DB::table('settings')
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            $type = $existing?->type ?? (is_bool($val) ? 'boolean' : (is_int($val) ? 'integer' : (is_array($val) ? 'json' : 'string')));

            $dbVal = match ($type) {
                'boolean' => $val ? '1' : '0',
                'json' => is_array($val) ? json_encode($val) : $val,
                default => (string)$val,
            };

            if ($existing) {
                DB::table('settings')
                    ->where('id', $existing->id)
                    ->update([
                        'value' => $dbVal,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('settings')->insert([
                    'group' => $group,
                    'key' => $key,
                    'value' => $dbVal,
                    'type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Return updated group
        $updatedRows = DB::table('settings')->where('group', $group)->get();
        $result = [];
        foreach ($updatedRows as $row) {
            $result[$row->key] = $this->castValue($row->value, $row->type ?? 'string');
        }

        return response()->json([
            'data' => $result,
            'meta' => null,
            'errors' => null,
        ]);
    }
}
