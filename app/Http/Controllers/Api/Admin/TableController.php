<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TableModel;
use App\Services\QrTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableController extends Controller
{
    public function __construct(
        private readonly QrTokenService $qrTokenService,
    ) {}

    public function index(Branch $branch )
{
    $tables = $branch->tables()
        ->with('qrCode:id,token,type,is_active,table_id')
        ->orderBy('table_number')
        ->get()
        ->map(function ($table) {
            if ($table->qrCode) {
                $table->qrCode->qr_image = url(
                    "/api/qr/{$table->qrCode->id}/image"
                );
            }

            return $table;
        });

    return response()->json([
        'data' => $tables,
    ]);
}


    /**
     * إنشاء طاولة + توليد QR تلقائيًا
     */
    public function store(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'table_number'       => ['required', 'string', 'max:255'],
            'manual_access_code' => ['nullable', 'string', 'min:4', 'max:20'],
            'radius_override'    => ['nullable', 'integer', 'min:10'],
            'generate_qr'        => ['nullable', 'boolean'], // default true
        ]);

        // منع تكرار رقم الطاولة داخل نفس الفرع
        $exists = $branch->tables()
            ->where('table_number', $data['table_number'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'رقم الطاولة موجود بالفعل في هذا الفرع.',
            ], 422);
        }

        $result = DB::transaction(function () use ($branch, $data) {
            $table = $branch->tables()->create([
                'table_number' => $data['table_number'],
            ]);

            $qrCode = null;
            if ($data['generate_qr'] ?? true) {
                $qrCode = $this->qrTokenService->generate(
                    branch: $branch,
                    table: $table,
                    radiusOverride: $data['radius_override'] ?? null,
                    manualCode: $data['manual_access_code'] ?? null,
                );
            }

            return compact('table', 'qrCode');
        });

        $table = $result['table']->fresh('qrCode');

        return response()->json([
            'message' => 'تم إنشاء الطاولة بنجاح',
            'data'    => [
                'table'       => $table,
                'qr_url'      => $result['qrCode']
                    ? $this->menuUrl($result['qrCode']->token)
                    : null,
                'qr_token'    => $result['qrCode']?->token,
            ],
        ], 201);
    }

    public function show(TableModel $table)
    {
        $table->load(['branch', 'qrCode']);

        return response()->json([
            'data' => [
                'table'    => $table,
                'qr_url'   => $table->qrCode
                    ? $this->menuUrl($table->qrCode->token)
                    : null,
                'qr_token' => $table->qrCode?->token,
            ],
        ]);
    }

    public function update(Request $request, TableModel $table)
    {
        $data = $request->validate([
            'table_number' => ['sometimes', 'string', 'max:255'],
        ]);

        if (isset($data['table_number'])) {
            $exists = TableModel::where('branch_id', $table->branch_id)
                ->where('table_number', $data['table_number'])
                ->where('id', '!=', $table->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'رقم الطاولة موجود بالفعل في هذا الفرع.',
                ], 422);
            }
        }

        $table->update($data);

        return response()->json([
            'message' => 'تم تحديث الطاولة',
            'data'    => $table->fresh('qrCode'),
        ]);
    }

    public function destroy(TableModel $table)
    {
        // لو فيه QR مرتبط، نلغيه
        if ($table->qr_code_id) {
            $table->qrCode?->update(['is_active' => false]);
        }

        $table->delete();

        return response()->json(['message' => 'تم حذف الطاولة']);
    }

    private function menuUrl(string $token): string
    {
        // غيّر الدومين حسب الفرونت عندك
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return "{$frontend}/menu/{$token}";
    }
}