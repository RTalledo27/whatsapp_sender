<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClubMetricsController extends Controller
{
    public function clientsStats(Request $request)
    {
        $baseQuery = Consulta::query()->whereNull('comercio_id');
        $this->applyDateFilters($baseQuery, $request);

        if ($request->filled('telefono')) {
            $baseQuery->where('telefono_origen', $request->query('telefono'));
        }

        if ($request->filled('dni')) {
            $baseQuery->where('dni', $request->query('dni'));
        }

        if ($request->filled('resultado')) {
            $baseQuery->where('resultado', $request->query('resultado'));
        }

        $limit = $this->normalizeLimit($request->query('limit'));

        $totals = [
            'total_consultas' => (clone $baseQuery)->count(),
            'unique_clients' => (clone $baseQuery)
                ->whereNotNull('telefono_origen')
                ->distinct('telefono_origen')
                ->count('telefono_origen'),
            'apto' => (clone $baseQuery)->where('resultado', 'apto')->count(),
            'no_apto' => (clone $baseQuery)->where('resultado', 'no_apto')->count(),
            'no_encontrado' => (clone $baseQuery)->where('resultado', 'no_encontrado')->count(),
        ];

        $byMonth = (clone $baseQuery)
            ->selectRaw('DATE_FORMAT(fecha_consulta, "%Y-%m") as month')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when resultado = "apto" then 1 else 0 end) as apto')
            ->selectRaw('sum(case when resultado = "no_apto" then 1 else 0 end) as no_apto')
            ->selectRaw('sum(case when resultado = "no_encontrado" then 1 else 0 end) as no_encontrado')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->get();

        $byPhone = (clone $baseQuery)
            ->whereNotNull('telefono_origen')
            ->selectRaw('telefono_origen')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when resultado = "apto" then 1 else 0 end) as apto')
            ->selectRaw('sum(case when resultado = "no_apto" then 1 else 0 end) as no_apto')
            ->selectRaw('sum(case when resultado = "no_encontrado" then 1 else 0 end) as no_encontrado')
            ->groupBy('telefono_origen')
            ->orderBy('total', 'desc')
            ->limit($limit)
            ->get();

        $byPhoneMonth = [];
        if ($request->filled('telefono')) {
            $byPhoneMonth = (clone $baseQuery)
                ->where('telefono_origen', $request->query('telefono'))
                ->selectRaw('telefono_origen')
                ->selectRaw('DATE_FORMAT(fecha_consulta, "%Y-%m") as month')
                ->selectRaw('count(*) as total')
                ->groupBy('telefono_origen', 'month')
                ->orderBy('month', 'desc')
                ->limit($limit)
                ->get();
        }

        return response()->json([
            'totals' => $totals,
            'by_month' => $byMonth,
            'by_phone' => $byPhone,
            'by_phone_month' => $byPhoneMonth,
        ]);
    }

    public function clientsDetails(Request $request)
    {
        $query = Consulta::query()->whereNull('comercio_id');
        $this->applyDateFilters($query, $request);

        if ($request->filled('telefono')) {
            $query->where('telefono_origen', $request->query('telefono'));
        }

        if ($request->filled('dni')) {
            $query->where('dni', $request->query('dni'));
        }

        if ($request->filled('resultado')) {
            $query->where('resultado', $request->query('resultado'));
        }

        $perPage = $request->integer('per_page', 20);

        $details = $query
            ->orderByDesc('fecha_consulta')
            ->select([
                'dni',
                'telefono_origen',
                'resultado',
                'fecha_consulta',
            ])
            ->paginate($perPage);

        return response()->json([
            'data' => $details->items(),
            'meta' => [
                'current_page' => $details->currentPage(),
                'last_page' => $details->lastPage(),
                'per_page' => $details->perPage(),
                'total' => $details->total(),
            ],
        ]);
    }

    public function commerceStats(Request $request)
    {
        $baseQuery = Consulta::query()->whereNotNull('comercio_id');
        $this->applyDateFilters($baseQuery, $request);

        if ($request->filled('comercio_id')) {
            $baseQuery->where('comercio_id', $request->query('comercio_id'));
        }

        if ($request->filled('dni')) {
            $baseQuery->where('dni', $request->query('dni'));
        }

        if ($request->filled('resultado')) {
            $baseQuery->where('resultado', $request->query('resultado'));
        }

        $limit = $this->normalizeLimit($request->query('limit'));

        $totals = [
            'total_consumos' => (clone $baseQuery)->count(),
            'comercios_con_consumo' => (clone $baseQuery)->distinct('comercio_id')->count('comercio_id'),
            'apto' => (clone $baseQuery)->where('resultado', 'apto')->count(),
            'no_apto' => (clone $baseQuery)->where('resultado', 'no_apto')->count(),
            'no_encontrado' => (clone $baseQuery)->where('resultado', 'no_encontrado')->count(),
        ];

        $byMonth = (clone $baseQuery)
            ->selectRaw('DATE_FORMAT(fecha_consulta, "%Y-%m") as month')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when resultado = "apto" then 1 else 0 end) as apto')
            ->selectRaw('sum(case when resultado = "no_apto" then 1 else 0 end) as no_apto')
            ->selectRaw('sum(case when resultado = "no_encontrado" then 1 else 0 end) as no_encontrado')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->get();

        $byComercio = (clone $baseQuery)
            ->join('comercios', 'consultas.comercio_id', '=', 'comercios.id')
            ->selectRaw('comercios.id as comercio_id')
            ->selectRaw('comercios.nombre as comercio_nombre')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when consultas.resultado = "apto" then 1 else 0 end) as apto')
            ->selectRaw('sum(case when consultas.resultado = "no_apto" then 1 else 0 end) as no_apto')
            ->selectRaw('sum(case when consultas.resultado = "no_encontrado" then 1 else 0 end) as no_encontrado')
            ->groupBy('comercios.id', 'comercios.nombre')
            ->orderBy('total', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'totals' => $totals,
            'by_month' => $byMonth,
            'by_comercio' => $byComercio,
        ]);
    }

    public function commerceDetails(Request $request)
    {
        $query = Consulta::query()->whereNotNull('comercio_id');
        $this->applyDateFilters($query, $request);

        if ($request->filled('comercio_id')) {
            $query->where('comercio_id', $request->query('comercio_id'));
        }

        if ($request->filled('dni')) {
            $query->where('dni', $request->query('dni'));
        }

        if ($request->filled('resultado')) {
            $query->where('resultado', $request->query('resultado'));
        }

        $perPage = $request->integer('per_page', 20);

        $details = $query
            ->leftJoin('comercios', 'consultas.comercio_id', '=', 'comercios.id')
            ->orderByDesc('fecha_consulta')
            ->select([
                'consultas.dni',
                'consultas.resultado',
                'consultas.fecha_consulta',
                'consultas.comercio_id',
                'comercios.nombre as comercio_nombre',
            ])
            ->paginate($perPage);

        return response()->json([
            'data' => $details->items(),
            'meta' => [
                'current_page' => $details->currentPage(),
                'last_page' => $details->lastPage(),
                'per_page' => $details->perPage(),
                'total' => $details->total(),
            ],
        ]);
    }

    private function applyDateFilters($query, Request $request): void
    {
        if ($request->filled('start_date')) {
            $query->whereDate('fecha_consulta', '>=', $request->query('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('fecha_consulta', '<=', $request->query('end_date'));
        }
    }

    private function normalizeLimit($limit): int
    {
        $limit = (int) ($limit ?? 100);
        if ($limit < 1) {
            return 1;
        }
        return min($limit, 500);
    }
}
