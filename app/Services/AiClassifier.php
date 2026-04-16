<?php

declare(strict_types=1);

namespace App\Services;

final class AiClassifier
{
  public function detectJudolPromotion(string $text): array
  {
    $cleanText = trim($text);
    if ($cleanText === '') {
      return [
        'is_judol_promotion' => false,
        'confidence' => 0.0,
        'reason' => '',
      ];
    }

    $apiKey = env('AI_API_KEY', '');
    $endpoint = (string) env('AI_ENDPOINT', '');

    if ($apiKey !== '' && $endpoint !== '') {
      $aiResult = $this->detectJudolPromotionWithAi($cleanText, $apiKey, $endpoint);
      if ($aiResult !== null) {
        return $aiResult;
      }
    }

    return $this->detectJudolPromotionFallback($cleanText);
  }

  public function classify(string $description, ?string $absoluteMediaPath = null, ?string $mimeType = null): array
  {
    $apiKey = env('AI_API_KEY', '');

    if ($apiKey === '') {
      return $this->fallbackClassification($description);
    }

    $hasImage = $absoluteMediaPath !== null
      && is_file($absoluteMediaPath)
      && $mimeType !== null
      && str_starts_with($mimeType, 'image/');

    $hasVideo = !$hasImage
      && $absoluteMediaPath !== null
      && is_file($absoluteMediaPath)
      && $mimeType === 'video/mp4';

    $extractedFrame = $hasVideo ? $this->extractVideoFrame($absoluteMediaPath) : null;

    $hasVisual     = $hasImage || $extractedFrame !== null;
    $imagePathForAi = $hasImage ? $absoluteMediaPath : $extractedFrame;
    $mimeForAi      = $hasImage ? $mimeType : ($extractedFrame !== null ? 'image/jpeg' : null);

    $prompt = 'Anda adalah AI klasifikasi masalah publik Indonesia berbasis multimodal (teks + foto/video bukti). '
      . 'Klasifikasikan laporan ke salah satu kategori: banjir, jalan_rusak, sampah, kriminalitas, kemacetan, listrik, lainnya. '
      . 'Tentukan urgency: critical atau normal. '
      . 'Aturan penting: '
      . '(0) Jika isi laporan dominan opini personal, gosip, hiburan, politik figur, atau topik yang tidak terkait layanan/fasilitas publik, gunakan kategori lainnya dan nyatakan bahwa konten di luar prioritas layanan publik. '
      . '(1) Jika ada foto atau frame video, observasi visual WAJIB dipertimbangkan. '
      . '(2) Jika deskripsi bertentangan dengan bukti visual (foto/frame video), prioritaskan bukti visual dan jelaskan singkat di ai_summary. '
      . '(3) Jangan halusinasi detail yang tidak terlihat di teks/foto. '
      . '(4) ai_summary wajib informatif dalam 3-5 kalimat (sekitar 140-320 karakter), mencakup: observasi inti, dampak publik, tingkat urgensi, dan saran tindak lanjut singkat. '
      . '(5) Tentukan juga apakah bukti visual (foto/frame video) konsisten dengan deskripsi lewat field boolean is_consistent (true/false) dan alasan singkat di consistency_reason. '
      . 'PERINTAH KETAT: BALAS HANYA JSON VALID. JANGAN TAMBAH TEKS SEBELUM/SESUDAH JSON. '
      . 'Format: {"category_ai":"...","urgency_ai":"...","confidence_ai":0.0,"ai_summary":"...","is_consistent":true,"consistency_reason":"..."}.';

    $content = [
      [
        'type' => 'text',
        'text' => $prompt
          . "\n\nDeskripsi laporan: " . $description
          . "\nStatus bukti visual: " . (
            $hasImage
            ? 'Ada foto bukti terlampir, gunakan untuk observasi visual.'
            : ($extractedFrame !== null
              ? 'Ada video bukti; frame representatif diekstrak, perlakukan seperti foto untuk observasi visual.'
              : 'Tidak ada bukti visual (foto/video) yang tersedia.')
          ),
      ],
    ];

    if ($imagePathForAi !== null && $mimeForAi !== null) {
      $binary = file_get_contents($imagePathForAi);
      if ($binary !== false) {
        $base64 = base64_encode($binary);
        $content[] = [
          'type' => 'image_url',
          'image_url' => [
            'url' => 'data:' . $mimeForAi . ';base64,' . $base64,
          ],
        ];
      }
    }

    $payload = [
      'model' => env('AI_MODEL', 'llama-3.2-11b-vision-preview'),
      'messages' => [
        [
          'role' => 'user',
          'content' => $content,
        ],
      ],
      'temperature' => 0.2,
      'max_tokens' => 300,
    ];

    $response = $this->httpPostJson((string) env('AI_ENDPOINT', ''), $payload, [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
    ]);

    if ($response === null) {
      if ($extractedFrame !== null && is_file($extractedFrame)) {
        @unlink($extractedFrame);
      }
      return $this->fallbackClassification($description);
    }

    $json = json_decode($response, true);
    $rawContent = $json['choices'][0]['message']['content'] ?? '';

    if (!is_string($rawContent) || trim($rawContent) === '') {
      if ($extractedFrame !== null && is_file($extractedFrame)) {
        @unlink($extractedFrame);
      }
      return $this->fallbackClassification($description);
    }

    $clean = trim($rawContent);
    if (str_starts_with($clean, '```')) {
      $clean = preg_replace('/^```(?:json)?|```$/m', '', $clean) ?? $clean;
      $clean = trim($clean);
    }

    $decoded = json_decode($clean, true);
    if (!is_array($decoded)) {
      if ($extractedFrame !== null && is_file($extractedFrame)) {
        @unlink($extractedFrame);
      }
      return $this->fallbackClassification($description);
    }

    $summary = $this->ensureRichSummary(
      trim((string) ($decoded['ai_summary'] ?? 'Analisis AI tidak tersedia.')),
      $description,
      $this->normalizeCategory((string) ($decoded['category_ai'] ?? 'lainnya')),
      $this->normalizeUrgency((string) ($decoded['urgency_ai'] ?? 'normal')),
      $hasVisual,
      $extractedFrame !== null
    );

    $result = [
      'category_ai' => $this->normalizeCategory((string) ($decoded['category_ai'] ?? 'lainnya')),
      'urgency_ai' => $this->normalizeUrgency((string) ($decoded['urgency_ai'] ?? 'normal')),
      'confidence_ai' => max(0.0, min(1.0, (float) ($decoded['confidence_ai'] ?? 0.6))),
      'ai_summary' => $summary,
      'is_consistent' => $hasVisual ? (bool) ($decoded['is_consistent'] ?? true) : true,
      'consistency_reason' => trim((string) ($decoded['consistency_reason'] ?? '')),
    ];

    if ($extractedFrame !== null && is_file($extractedFrame)) {
      @unlink($extractedFrame);
    }

    return $result;
  }

  private function ensureRichSummary(string $summary, string $description, string $category, string $urgency, bool $hasImage, bool $isVideo = false): string
  {
    $summary = trim($summary);
    if (mb_strlen($summary) >= 220 && !$this->looksFlatSummary($summary)) {
      return $summary;
    }

    $context = $this->analyzeContextFlags($description);
    $visualKey = $hasImage ? ($isVideo ? 'video' : 'img') : 'text';
    $seed = $description . '|' . $category . '|' . $urgency . '|' . $visualKey;

    $urgencyText = $urgency === 'critical'
      ? $this->pickVariant($seed, 'urgency_critical', [
        'Tingkat urgensi dinilai tinggi sehingga perlu respon cepat lintas instansi.',
        'Urgensi berada pada level kritis dan memerlukan tindakan cepat agar dampak tidak meluas.',
        'Situasi ini masuk prioritas tinggi, sehingga koordinasi penanganan sebaiknya dipercepat.',
      ])
      : $this->pickVariant($seed, 'urgency_normal', [
        'Tingkat urgensi dinilai normal, namun tetap perlu ditindaklanjuti agar tidak membesar.',
        'Urgensi saat ini tergolong normal, tetapi pemantauan dan tindak lanjut tetap diperlukan.',
        'Kasus belum darurat, namun penanganan bertahap tetap penting untuk mencegah eskalasi.',
      ]);

    if ($hasImage && $isVideo) {
      $sourceText = $this->pickVariant($seed, 'source_video_frame', [
        'Penilaian disusun dari deskripsi dan frame video yang diekstrak sebagai bukti visual.',
        'Analisis mempertimbangkan keterangan teks dan observasi visual dari frame representatif video bukti.',
        'Kesimpulan dibangun dari narasi laporan dan screenshot frame video yang terlampir.',
      ]);
    } elseif ($hasImage) {
      $sourceText = $this->pickVariant($seed, 'source_with_image', [
        'Penilaian disusun dari deskripsi dan bukti visual yang terlampir.',
        'Analisis menggabungkan keterangan teks dengan observasi visual dari lampiran gambar.',
        'Kesimpulan dibangun dari narasi laporan serta informasi yang terlihat pada bukti foto.',
      ]);
    } else {
      $sourceText = $this->pickVariant($seed, 'source_text_only', [
        'Penilaian disusun dari deskripsi laporan karena bukti visual belum tersedia.',
        'Analisis saat ini bertumpu pada narasi pelapor karena belum ada dukungan foto lapangan.',
        'Kesimpulan sementara dibuat dari teks laporan, dengan catatan bukti visual belum dilampirkan.',
      ]);
    }

    $snippet = trim($description);
    if (mb_strlen($snippet) > 90) {
      $snippet = mb_substr($snippet, 0, 90) . '...';
    }

    if ($category === 'lainnya' && $context['nonPublic'] && !$context['publicService']) {
      $nonPublicLead = $this->pickVariant($seed, 'non_public_lead', [
        'Konten laporan lebih banyak berisi opini personal/non-layanan publik',
        'Isi laporan cenderung mengarah ke komentar personal yang belum menunjukkan isu layanan publik',
        'Materi laporan dominan berupa opini atau narasi non-layanan publik',
      ]);

      return $nonPublicLead . ': "' . $snippet . '". '
        . $sourceText . ' '
        . $this->pickVariant($seed, 'non_public_mid', [
          'Indikasi belum menunjukkan gangguan fasilitas atau layanan publik yang spesifik.',
          'Belum tampak indikasi langsung terhadap gangguan sarana atau layanan publik yang terukur.',
          'Dampak ke fasilitas umum belum terlihat jelas dari informasi yang tersedia.',
        ]) . ' '
        . $this->pickVariant($seed, 'non_public_tail', [
          'Saran: arahkan ke kanal aduan yang lebih tepat atau minta pelapor menambahkan fakta kejadian yang berdampak ke publik.',
          'Saran: minta pelapor melengkapi bukti kejadian dan indikator dampak publik agar laporan bisa diprioritaskan.',
          'Saran: lakukan klarifikasi agar laporan memuat fakta operasional layanan publik, bukan opini personal semata.',
        ]);
    }

    $categoryLine = match ($category) {
      'banjir' => $this->pickVariant($seed, 'category_banjir', [
        'Indikasi utama mengarah pada kejadian genangan/banjir yang berpotensi mengganggu mobilitas warga.',
        'Laporan menunjukkan sinyal permasalahan banjir/genangan yang dapat menghambat akses lingkungan.',
        'Temuan awal mengarah pada gangguan banjir yang berisiko mempengaruhi aktivitas harian masyarakat.',
      ]),
      'jalan_rusak' => $this->pickVariant($seed, 'category_jalan_rusak', [
        'Indikasi utama mengarah pada kerusakan infrastruktur jalan yang dapat memicu risiko kecelakaan.',
        'Laporan mengarah pada masalah kondisi jalan yang menurunkan keselamatan dan kenyamanan berkendara.',
        'Temuan awal menunjukkan indikasi kerusakan jalan yang perlu penanganan teknis bertahap.',
      ]),
      'sampah' => $this->pickVariant($seed, 'category_sampah', [
        'Indikasi utama mengarah pada persoalan sampah/limbah yang berdampak pada kebersihan dan kesehatan lingkungan.',
        'Laporan menandakan masalah pengelolaan sampah yang berpotensi menurunkan kualitas lingkungan sekitar.',
        'Temuan awal menunjukkan isu sampah/limbah yang membutuhkan tindak lanjut kebersihan kawasan.',
      ]),
      'kriminalitas' => $this->pickVariant($seed, 'category_kriminalitas', [
        'Indikasi utama mengarah pada potensi gangguan keamanan yang membutuhkan koordinasi aparat setempat.',
        'Laporan menunjukkan sinyal isu keamanan lingkungan yang perlu validasi dan respon aparat.',
        'Temuan awal mengarah pada dugaan gangguan kriminalitas yang perlu ditangani secara kolaboratif.',
      ]),
      'kemacetan' => $this->pickVariant($seed, 'category_kemacetan', [
        'Indikasi utama mengarah pada kemacetan lalu lintas yang menurunkan kelancaran aktivitas harian.',
        'Laporan menunjukkan gangguan arus lalu lintas yang berpotensi memperpanjang waktu tempuh warga.',
        'Temuan awal mengarah pada titik kemacetan yang memerlukan evaluasi rekayasa lalu lintas.',
      ]),
      'listrik' => $this->pickVariant($seed, 'category_listrik', [
        'Indikasi utama mengarah pada gangguan kelistrikan yang dapat menghambat layanan dan aktivitas warga.',
        'Laporan menandakan masalah pasokan listrik yang berpotensi mengganggu kegiatan rumah tangga dan usaha.',
        'Temuan awal menunjukkan indikasi gangguan listrik yang perlu tindak lanjut teknis di lapangan.',
      ]),
      default => $this->pickVariant($seed, 'category_lainnya', [
        'Indikasi utama mengarah ke kategori lainnya berdasarkan informasi laporan yang tersedia.',
        'Informasi saat ini belum cukup untuk masuk kategori spesifik sehingga diklasifikasikan sebagai lainnya.',
        'Temuan awal belum mengarah kuat ke kategori teknis tertentu dan sementara ditempatkan di kategori lainnya.',
      ]),
    };

    $impactLine = match ($category) {
      'banjir' => 'Dampak potensial mencakup akses lingkungan terhambat, risiko kesehatan, dan kerusakan aset warga.',
      'jalan_rusak' => 'Dampak potensial mencakup kecelakaan, kerusakan kendaraan, dan perlambatan distribusi/logistik.',
      'sampah' => 'Dampak potensial mencakup bau, peningkatan vektor penyakit, serta kualitas ruang publik yang menurun.',
      'kriminalitas' => 'Dampak potensial mencakup rasa tidak aman, kerugian material, serta penurunan aktivitas warga pada jam tertentu.',
      'kemacetan' => 'Dampak potensial mencakup keterlambatan perjalanan, pemborosan bahan bakar, dan emisi yang meningkat.',
      'listrik' => 'Dampak potensial mencakup terganggunya usaha, komunikasi, dan layanan dasar pada area terdampak.',
      default => 'Dampak publik belum terukur jelas sehingga perlu verifikasi lapangan untuk memetakan prioritas penanganan.',
    };

    $followUpLine = match ($category) {
      'banjir' => 'Tindak lanjut awal: cek saluran drainase, titik sumbatan, dan siapkan langkah pemompaan/normalisasi lokal.',
      'jalan_rusak' => 'Tindak lanjut awal: dokumentasi kerusakan, pengamanan titik rawan, lalu penjadwalan perbaikan bertahap.',
      'sampah' => 'Tindak lanjut awal: pengangkutan prioritas, audit titik pembuangan liar, dan edukasi/penertiban kawasan.',
      'kriminalitas' => 'Tindak lanjut awal: validasi kronologi, koordinasi RT/RW-aparat, serta penguatan patroli pada jam rawan.',
      'kemacetan' => 'Tindak lanjut awal: cek titik bottleneck, atur rekayasa arus sementara, dan evaluasi manajemen simpang.',
      'listrik' => 'Tindak lanjut awal: verifikasi cakupan padam, pelaporan ke petugas teknis, dan mitigasi kebutuhan layanan kritis.',
      default => 'Tindak lanjut awal: validasi fakta lapangan dan lengkapi bukti agar kategori penanganan lebih presisi.',
    };

    return $categoryLine . ' '
      . 'Ringkasan laporan: "' . $snippet . '". '
      . $sourceText . ' '
      . $urgencyText . ' '
      . $impactLine . ' '
      . $followUpLine;
  }

  private function looksFlatSummary(string $summary): bool
  {
    $text = mb_strtolower(trim($summary));
    if ($text === '') {
      return true;
    }

    $flatMarkers = [
      'indikasi utama mengarah ke kategori',
      'analisis didasarkan pada deskripsi laporan',
      'dampak yang mungkin terjadi adalah terganggunya aktivitas warga',
      'disarankan verifikasi lapangan',
      'klasifikasi fallback berbasis kata kunci lokal',
    ];

    $hits = 0;
    foreach ($flatMarkers as $marker) {
      if (mb_strpos($text, $marker) !== false) {
        $hits++;
      }
    }

    return $hits >= 2;
  }

  private function pickVariant(string $seed, string $bucket, array $options): string
  {
    if (count($options) === 0) {
      return '';
    }

    $hashInput = $seed . '|' . $bucket;
    $index = abs((int) crc32($hashInput)) % count($options);
    return (string) $options[$index];
  }

  private function analyzeContextFlags(string $description): array
  {
    $text = mb_strtolower($description);

    $publicKeywords = [
      'jalan',
      'jembatan',
      'drainase',
      'banjir',
      'genangan',
      'lampu jalan',
      'trotoar',
      'sampah',
      'limbah',
      'selokan',
      'macet',
      'lalu lintas',
      'kriminal',
      'pencurian',
      'begal',
      'listrik',
      'padam',
      'puskesmas',
      'sekolah',
      'fasilitas umum',
      'pelayanan publik',
      'halte',
      'terminal',
    ];

    $nonPublicKeywords = [
      'podcast',
      'artis',
      'seleb',
      'gosip',
      'konten',
      'ketawa',
      'pejabat',
      'menteri',
      'politik',
      'kampanye',
      'opini',
      'curhat',
      'drama',
      'hiburan',
      'viral',
      'pribadi',
    ];

    $publicHits = 0;
    foreach ($publicKeywords as $keyword) {
      if (mb_strpos($text, $keyword) !== false) {
        $publicHits++;
      }
    }

    $nonPublicHits = 0;
    foreach ($nonPublicKeywords as $keyword) {
      if (mb_strpos($text, $keyword) !== false) {
        $nonPublicHits++;
      }
    }

    return [
      'publicService' => $publicHits > 0,
      'nonPublic' => $nonPublicHits >= 2 || ($nonPublicHits > 0 && $publicHits === 0),
    ];
  }

  private function extractVideoFrame(string $videoPath): ?string
  {
    $ffmpegPath = (string) env(
      'FFMPEG_PATH',
      'C:\Program Files (x86)\Labcenter Electronics\Proteus 8 Professional\BIN\ffmpeg.exe'
    );

    if (!is_file($ffmpegPath)) {
      return null;
    }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
      . 'rlp_frame_' . bin2hex(random_bytes(8)) . '.jpg';

    // Coba ekstrak frame di detik ke-1, fallback ke 0 untuk video sangat pendek
    foreach (['1', '0'] as $seekSec) {
      $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ];

      $proc = proc_open(
        [$ffmpegPath, '-y', '-ss', $seekSec, '-i', $videoPath, '-vframes', '1', '-f', 'image2', '-q:v', '2', $tmpPath],
        $spec,
        $pipes
      );

      if (!is_resource($proc)) {
        continue;
      }

      fclose($pipes[0]);
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($proc);

      if ($exitCode === 0 && is_file($tmpPath) && filesize($tmpPath) > 0) {
        return $tmpPath;
      }

      if (is_file($tmpPath)) {
        @unlink($tmpPath);
      }
    }

    return null;
  }

  private function httpPostJson(string $url, array $payload, array $headers): ?string
  {
    if ($url === '') {
      return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
      return null;
    }

    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
      return null;
    }

    return $result;
  }

  private function fallbackClassification(string $description): array
  {
    $text = strtolower($description);
    $context = $this->analyzeContextFlags($description);

    $rules = [
      'banjir' => ['banjir', 'genangan', 'meluap', 'drainase'],
      'jalan_rusak' => ['jalan rusak', 'berlubang', 'aspal', 'retak'],
      'sampah' => ['sampah', 'limbah', 'bau'],
      'kriminalitas' => ['maling', 'begal', 'perampokan', 'kriminal'],
      'kemacetan' => ['macet', 'kemacetan', 'padat'],
      'listrik' => ['listrik', 'mati lampu', 'korsleting'],
    ];

    $category = 'lainnya';
    foreach ($rules as $candidate => $keywords) {
      foreach ($keywords as $keyword) {
        if (str_contains($text, $keyword)) {
          $category = $candidate;
          break 2;
        }
      }
    }

    $criticalWords = ['parah', 'darurat', 'besar', 'tinggi', 'bahaya', 'korban'];
    $urgency = 'normal';
    foreach ($criticalWords as $word) {
      if (str_contains($text, $word)) {
        $urgency = 'critical';
        break;
      }
    }

    $snippet = trim($description);
    if (mb_strlen($snippet) > 90) {
      $snippet = mb_substr($snippet, 0, 90) . '...';
    }

    $fallbackSummary = 'Klasifikasi sementara berbasis kata kunci lokal: kategori ' . $category
      . ' dengan urgensi ' . $urgency . '. '
      . 'Ringkasan laporan: "' . $snippet . '". '
      . 'Saran: lakukan verifikasi lapangan untuk validasi akhir.';

    if ($category === 'lainnya' && $context['nonPublic'] && !$context['publicService']) {
      $fallbackSummary = 'Klasifikasi sementara: konten cenderung opini/non-layanan publik. '
        . 'Ringkasan laporan: "' . $snippet . '". '
        . 'Saran: minta pelapor menambahkan fakta dampak publik yang konkret agar dapat diproses sebagai aduan layanan.';
    }

    return [
      'category_ai' => $category,
      'urgency_ai' => $urgency,
      'confidence_ai' => 0.58,
      'ai_summary' => $fallbackSummary,
      'is_consistent' => true,
      'consistency_reason' => '',
    ];
  }

  private function detectJudolPromotionWithAi(string $text, string $apiKey, string $endpoint): ?array
  {
    $prompt = 'Anda adalah moderator konten Indonesia. Tugas Anda hanya menilai apakah teks mempromosikan judi online (judol). '
      . 'Kembalikan JSON valid tanpa teks tambahan dengan format: '
      . '{"is_judol_promotion":true/false,"confidence":0.0,"reason":"..."}. '
      . 'Aturan: '
      . '(1) true hanya jika ada unsur promosi/ajakan/penawaran judol, link judol, bonus, deposit, atau ajakan bermain. '
      . '(2) false untuk teks yang melaporkan, mengecam, memperingatkan, atau meminta penindakan judol tanpa mempromosikan. '
      . '(3) reason singkat maksimal 1 kalimat.';

    $payload = [
      'model' => env('AI_MODEL', 'llama-3.2-11b-vision-preview'),
      'messages' => [
        [
          'role' => 'user',
          'content' => [
            [
              'type' => 'text',
              'text' => $prompt . "\n\nTeks: " . $text,
            ],
          ],
        ],
      ],
      'temperature' => 0.0,
      'max_tokens' => 120,
    ];

    $response = $this->httpPostJson($endpoint, $payload, [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
    ]);

    if ($response === null) {
      return null;
    }

    $json = json_decode($response, true);
    $rawContent = $json['choices'][0]['message']['content'] ?? '';
    if (!is_string($rawContent) || trim($rawContent) === '') {
      return null;
    }

    $clean = trim($rawContent);
    if (str_starts_with($clean, '```')) {
      $clean = preg_replace('/^```(?:json)?|```$/m', '', $clean) ?? $clean;
      $clean = trim($clean);
    }

    $decoded = json_decode($clean, true);
    if (!is_array($decoded)) {
      return null;
    }

    return [
      'is_judol_promotion' => (bool) ($decoded['is_judol_promotion'] ?? false),
      'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0.0))),
      'reason' => trim((string) ($decoded['reason'] ?? '')),
    ];
  }

  private function detectJudolPromotionFallback(string $text): array
  {
    $lower = mb_strtolower($text);

    $judolKeywords = [
      'judol',
      'judi online',
      'slot',
      'slot gacor',
      'casino',
      'bet',
      'togel',
      'situs judi',
      'link alternatif',
      'deposit',
      'wd',
      'withdraw',
      'rtp',
      'maxwin',
      'bonus new member',
    ];

    $promoKeywords = [
      'daftar',
      'main di',
      'mainkan',
      'gaskeun',
      'cuan',
      'menang besar',
      'link',
      'klik',
      'promo',
      'bonus',
      'referral',
    ];

    $antiKeywords = [
      'laporkan',
      'tolong tindak',
      'tangkap',
      'berantas',
      'bahaya',
      'penipuan',
      'jangan',
      'stop',
      'dilarang',
      'meresahkan',
    ];

    $judolHits = 0;
    foreach ($judolKeywords as $kw) {
      if (mb_strpos($lower, $kw) !== false) {
        $judolHits++;
      }
    }

    $promoHits = 0;
    foreach ($promoKeywords as $kw) {
      if (mb_strpos($lower, $kw) !== false) {
        $promoHits++;
      }
    }

    $antiHits = 0;
    foreach ($antiKeywords as $kw) {
      if (mb_strpos($lower, $kw) !== false) {
        $antiHits++;
      }
    }

    $hasLink = (bool) preg_match('/https?:\/\/|www\.|t\.me\//i', $text);

    $isPromotion = $judolHits > 0
      && ($promoHits > 0 || $hasLink)
      && !($antiHits > 0 && $promoHits === 0);

    if (!$isPromotion) {
      return [
        'is_judol_promotion' => false,
        'confidence' => 0.15,
        'reason' => '',
      ];
    }

    $confidence = min(0.98, 0.55 + ($judolHits * 0.12) + ($promoHits * 0.08) + ($hasLink ? 0.1 : 0.0));

    return [
      'is_judol_promotion' => true,
      'confidence' => $confidence,
      'reason' => 'Teks terdeteksi mengandung promosi atau ajakan judi online.',
    ];
  }

  private function normalizeCategory(string $value): string
  {
    $allowed = ['banjir', 'jalan_rusak', 'sampah', 'kriminalitas', 'kemacetan', 'listrik', 'lainnya'];
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : 'lainnya';
  }

  private function normalizeUrgency(string $value): string
  {
    $value = strtolower(trim($value));
    return $value === 'critical' ? 'critical' : 'normal';
  }
}
