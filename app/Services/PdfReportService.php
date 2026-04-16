<?php

declare(strict_types=1);

namespace App\Services;

final class PdfReportService
{
  public function generate(array $report, string $outputPath): void
  {
    $regionParts = [
      trim((string) ($report['province'] ?? '')),
      trim((string) ($report['city'] ?? '')),
      trim((string) ($report['district'] ?? '')),
      trim((string) ($report['subdistrict'] ?? '')),
    ];
    $regionParts = array_values(array_filter($regionParts, static fn(string $v): bool => $v !== ''));
    $regionText = count($regionParts) > 0 ? implode(', ', $regionParts) : '-';

    $lines = [
      'LAPORAN MASALAH - REAL-LIFE PROBLEM RADAR',
      'Waktu: ' . ($report['created_at'] ?? '-'),
      'Judul: ' . ($report['title'] ?? '-'),
      'Kategori User: ' . ($report['category_user'] ?? '-'),
      'Kategori AI: ' . ($report['category_ai'] ?? '-'),
      'Urgensi AI: ' . ($report['urgency_ai'] ?? '-'),
      'Confidence AI: ' . number_format((float) ($report['confidence_ai'] ?? 0), 2),
      'Koordinat: ' . ($report['latitude'] ?? '-') . ', ' . ($report['longitude'] ?? '-'),
      'Lokasi Daerah: ' . $regionText,
      'Status: ' . ($report['status'] ?? '-'),
      'Validasi Publik: +' . ($report['confirms'] ?? 0) . ' / -' . ($report['rejects'] ?? 0),
      '',
      'Deskripsi:',
      (string) ($report['description'] ?? '-'),
      '',
      'Ringkasan AI:',
      (string) ($report['ai_summary'] ?? '-'),
      '',
      'Dokumen ini dihasilkan otomatis oleh sistem untuk kebutuhan pelaporan ke instansi terkait.',
    ];

    $wrappedLines = [];
    foreach ($lines as $line) {
      $this->appendWrapped($wrappedLines, (string) $line, 95);
    }

    $contentStream = "BT\n/F1 11 Tf\n14 TL\n50 790 Td\n";
    foreach ($wrappedLines as $line) {
      $escaped = $this->escapePdfText($line);
      $contentStream .= "(" . $escaped . ") Tj\nT*\n";
    }
    $contentStream .= "ET";

    $objects = [];

    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Length " . strlen($contentStream) . " >>\nstream\n" . $contentStream . "\nendstream\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
      $offsets[$index + 1] = strlen($pdf);
      $pdf .= $object;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
      $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    file_put_contents($outputPath, $pdf);
  }

  private function escapePdfText(string $value): string
  {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
  }

  private function appendWrapped(array &$target, string $line, int $maxChars): void
  {
    $clean = $this->normalizeLine($line);
    if ($clean === '') {
      $target[] = '';
      return;
    }

    $wrapped = wordwrap($clean, $maxChars, "\n", true);
    foreach (explode("\n", $wrapped) as $part) {
      $target[] = $part;
    }
  }

  private function normalizeLine(string $value): string
  {
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
  }
}
