<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportRepository;

final class RiskPredictor
{
  private ReportRepository $reports;

  public function __construct(ReportRepository $reports)
  {
    $this->reports = $reports;
  }

  public function predict(float $latitude, float $longitude): array
  {
    $floodReports = $this->reports->recentByCategoryAndRange('banjir', $latitude, $longitude, 48);
    $roadReports = $this->reports->recentByCategoryAndRange('jalan_rusak', $latitude, $longitude, 72);
    $crimeReports = $this->reports->recentByCategoryAndRange('kriminalitas', $latitude, $longitude, 72);

    $score = (count($floodReports) * 25) + (count($roadReports) * 10) + (count($crimeReports) * 8);
    $score = min(100, $score);

    $level = 'low';
    if ($score >= 70) {
      $level = 'high';
    } elseif ($score >= 40) {
      $level = 'medium';
    }

    $message = match ($level) {
      'high' => 'Potensi gangguan tinggi dalam 1-2 hari. Prioritaskan mitigasi cepat di area ini.',
      'medium' => 'Potensi gangguan menengah. Pantau kondisi lapangan dan siapkan tindak lanjut.',
      default => 'Risiko relatif rendah berdasarkan laporan terbaru. Tetap lakukan pemantauan berkala.',
    };

    return [
      'risk_score' => $score,
      'risk_level' => $level,
      'message' => $message,
      'signals' => [
        'banjir_48_jam' => count($floodReports),
        'jalan_rusak_72_jam' => count($roadReports),
        'kriminalitas_72_jam' => count($crimeReports),
      ],
      'horizon' => '1-2 hari',
    ];
  }
}
