<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\QrCode;
use App\Models\TableModel;
use App\Services\QrTokenService;
use Endroid\QrCode\QrCode as QrCodeGenerator;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;

class QrCodeController extends Controller
{
    public function __construct(
        private readonly QrTokenService $qrTokenService,
    ) {}

    /**
     * GET /api/qr/{qrCode}/image  ← اللينك ده (عام بدون auth)
     * دوس عليه → تفتح صورة الـ QR
     */
  public function image(QrCode $qrCode)
{
    if (! $qrCode->is_active) {
        abort(404, 'رمز QR غير فعّال');
    }

    $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
    $menuUrl  = "{$frontend}/menu/{$qrCode->token}";

    $qr = new QrCodeGenerator(
        data: $menuUrl,
        size: 400,
        margin: 16,
    );

    $writer = new PngWriter();
    $result = $writer->write($qr);

    return response($result->getString(), 200, [
        'Content-Type'  => $result->getMimeType(),
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

    public function index(Branch $branch)
    {
        $qrCodes = $branch->qrCodes()
            ->with('table:id,table_number')
            ->orderByDesc('id')
            ->get()
            ->map(fn (QrCode $qr) => $this->format($qr));

        return response()->json(['data' => $qrCodes]);
    }

    public function store(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'table_id'           => ['nullable', 'exists:tables,id'],
            'manual_access_code' => ['nullable', 'string', 'min:4', 'max:20'],
            'radius_override'    => ['nullable', 'integer', 'min:10'],
        ]);

        $table = null;
        if (! empty($data['table_id'])) {
            $table = TableModel::where('id', $data['table_id'])
                ->where('branch_id', $branch->id)
                ->firstOrFail();
        }

        $qrCode = $this->qrTokenService->generate(
            branch: $branch,
            table: $table,
            radiusOverride: $data['radius_override'] ?? null,
            manualCode: $data['manual_access_code'] ?? null,
        );

        return response()->json([
            'message' => 'تم إنشاء رمز QR بنجاح',
            'data'    => $this->format($qrCode->load('table')),
        ], 201);
    }

    public function generateForTable(Request $request, TableModel $table)
    {
        $data = $request->validate([
            'manual_access_code' => ['nullable', 'string', 'min:4', 'max:20'],
            'radius_override'    => ['nullable', 'integer', 'min:10'],
        ]);

        if ($table->qr_code_id) {
            QrCode::where('id', $table->qr_code_id)->update(['is_active' => false]);
        }

        $qrCode = $this->qrTokenService->generate(
            branch: $table->branch,
            table: $table,
            radiusOverride: $data['radius_override'] ?? null,
            manualCode: $data['manual_access_code'] ?? null,
        );

        return response()->json([
            'message' => 'تم توليد QR للطاولة',
            'data'    => $this->format($qrCode->load('table')),
        ], 201);
    }

    public function rotate(QrCode $qrCode)
    {
        $qrCode = $this->qrTokenService->rotate($qrCode);

        return response()->json([
            'message' => 'تم تجديد التوكين — اطبع QR جديد',
            'data'    => $this->format($qrCode->load('table')),
        ]);
    }

    public function setManualCode(Request $request, QrCode $qrCode)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:20'],
        ]);

        $qrCode = $this->qrTokenService->regenerateManualCode($qrCode, $data['code']);

        return response()->json([
            'message' => 'تم تحديث كود الدخول اليدوي',
            'data'    => $this->format($qrCode->load('table')),
        ]);
    }

    public function update(Request $request, QrCode $qrCode)
    {
        $data = $request->validate([
            'is_active'       => ['nullable', 'boolean'],
            'radius_override' => ['nullable', 'integer', 'min:10'],
            'expires_at'      => ['nullable', 'date'],
        ]);

        $qrCode->update($data);

        return response()->json([
            'message' => 'تم تحديث الـ QR',
            'data'    => $this->format($qrCode->fresh('table')),
        ]);
    }

    public function destroy(QrCode $qrCode)
    {
        $qrCode->update(['is_active' => false]);
        TableModel::where('qr_code_id', $qrCode->id)->update(['qr_code_id' => null]);

        return response()->json(['message' => 'تم إلغاء تفعيل الـ QR']);
    }

    private function format(QrCode $qr): array
    {
        return [
            'id'              => $qr->id,
            'branch_id'       => $qr->branch_id,
            'table_id'        => $qr->table_id,
            'table_number'    => $qr->table?->table_number,
            'type'            => $qr->type,
            'token'           => $qr->token,
            'qr_image'        => url("/api/qr/{$qr->id}/image"),
            'is_active'       => $qr->is_active,
            'radius_override' => $qr->radius_override,
            'expires_at'      => $qr->expires_at,
            'created_at'      => $qr->created_at,
        ];
    }
}