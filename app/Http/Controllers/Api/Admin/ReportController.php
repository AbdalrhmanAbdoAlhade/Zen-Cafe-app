<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportFilterRequest;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService
    ) {}
  
  			
      /**
     * تقرير السلال المتروكة والاستعادة
     * GET /admin/reports/abandoned-carts
     */
    public function abandonedCartsReport(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->abandonedCartsReport($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
  
  		    /**
     * نشاط المستخدمين والصلاحيات
     * GET /admin/reports/user-activity
     */
    public function userActivityReport(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->userActivityReport($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
  		
  		    /**
     * تقرير المخزون + تنبيهات النفاد
     * GET /admin/reports/inventory
     */
    public function inventoryReport(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->inventoryReport($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
  	    /**
     * مصادر الزيارات + معدل التحويل
     * GET /admin/reports/traffic-conversion
     */
    public function trafficAndConversion(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->trafficAndConversion($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
      /**
     * تقرير نقاط الولاء
     * GET /admin/reports/loyalty-points
     */
    public function loyaltyPointsReport(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->loyaltyPointsReport($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
      /**
     * شرائح العملاء + المتوقفون عن الشراء
     * GET /admin/reports/customer-segments
     */
    public function customerSegmentsAndChurn(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->customerSegmentsAndChurn($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
    /**
     * تكرار الشراء + نسبة الاحتفاظ
     * GET /admin/reports/purchase-frequency-retention
     */
    public function purchaseFrequencyAndRetention(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->purchaseFrequencyAndRetention($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }  /**
     * تقرير العملاء الجدد والمتكررين
     * GET /admin/reports/customers-new-vs-returning
     */
    public function customersNewVsReturning(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->customersNewVsReturning($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
    /**
     * تقرير الخصومات والضريبة
     * GET /admin/reports/discounts-and-tax
     */
    public function discountsAndTax(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->discountsAndTax($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

      /**
     * تقرير زمن التجهيز وزمن تنفيذ الطلب
     * GET /admin/reports/fulfillment-times
     */
    public function fulfillmentTimes(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->fulfillmentTimes($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
    /**
     * تقرير عمليات الدفع: ناجحة / فاشلة + أسباب الفشل
     * GET /admin/reports/payment-operations
     */
    public function paymentOperations(ReportFilterRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->paymentOperations($request->filters());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}