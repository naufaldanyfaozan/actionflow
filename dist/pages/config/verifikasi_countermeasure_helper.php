<?php

/**
 * Shared logic for the staff_imp "Verifikasi Countermeasure" feature:
 * a countermeasure (asakai or FPM) is ready to verify once the related
 * dept has uploaded a bukti-perbaikan file but the status hasn't been
 * moved to CLOSED yet. Used by both sidebar.php (badge count) and
 * verifikasi_countermeasure.php (the actual list).
 */

function resolveVerifikasiPlant(string $username, ?string $requestedPlant): string
{
    if (!empty($requestedPlant)) {
        return $requestedPlant;
    }
    if ($username === 'doni') {
        return '1';
    }
    if ($username === 'aji') {
        return '3';
    }
    return '';
}

function buildVerifikasiPlantClause(mysqli $koneksi, string $plant, string $column): string
{
    if ($plant === '') {
        return '';
    }
    $plantEsc = mysqli_real_escape_string($koneksi, $plant);
    return "AND $column = '$plantEsc'";
}

/**
 * Staff QA hanya memverifikasi FPM Claim Machining (no_form FPM-Cast, flow_type CLAIMMACH);
 * Staff IMP memverifikasi sisanya (FPM-D Down Time & FPM-N NG Internal), tanpa FPM-Cast.
 */
function buildVerifikasiFpmFlowClause(bool $onlyClaimMach): string
{
    // Dikenali dari flow_type, jenis_fpm, maupun prefix no_form - data lama/hosting bisa
    // punya flow_type kosong walau no_form-nya FPM-Cast.
    // COALESCE wajib: tanpa itu kolom NULL bikin NOT (...) bernilai NULL dan FPM-D/FPM-N ikut hilang.
    $isClaimMach = "(COALESCE(h.flow_type,'') = 'CLAIMMACH' OR COALESCE(h.jenis_fpm,'') = 'Claim Machining' OR COALESCE(h.no_form,'') LIKE 'FPM-Cast/%')";
    return $onlyClaimMach ? "AND $isClaimMach" : "AND NOT $isClaimMach";
}

/**
 * @return array{asakai: int, fpm: int, total: int}
 */
function countPendingCountermeasureVerifikasi(mysqli $koneksi, string $plant, bool $onlyClaimMach = false): array
{
    $plantClause = buildVerifikasiPlantClause($koneksi, $plant, 'h.plant');
    $fpmClause = buildVerifikasiFpmFlowClause($onlyClaimMach);

    $qAsakai = mysqli_query($koneksi, "SELECT COUNT(*) AS cnt FROM (
        SELECT cd.id_cm_dt AS id FROM asakai_countermeasure_downtime cd
        JOIN asakai_header h ON h.id_asakai = cd.id_asakai
        WHERE (NULLIF(cd.file1,'') IS NOT NULL OR NULLIF(cd.file2,'') IS NOT NULL OR NULLIF(cd.file3,'') IS NOT NULL)
          AND (cd.status IS NULL OR cd.status != 'CLOSED')
          $plantClause
        UNION ALL
        SELECT cn.id_cm_ng AS id FROM asakai_countermeasure_ng cn
        JOIN asakai_header h ON h.id_asakai = cn.id_asakai
        WHERE (NULLIF(cn.file1,'') IS NOT NULL OR NULLIF(cn.file2,'') IS NOT NULL OR NULLIF(cn.file3,'') IS NOT NULL)
          AND (cn.status IS NULL OR cn.status != 'CLOSED')
          $plantClause
    ) t");
    $asakaiCount = (int) (mysqli_fetch_assoc($qAsakai)['cnt'] ?? 0);

    $qFpm = mysqli_query($koneksi, "SELECT COUNT(*) AS cnt FROM (
        SELECT ca.id AS id FROM fpm_corrective_action ca
        JOIN fpm_header h ON h.id_fpm = ca.id_fpm
        WHERE ca.file IS NOT NULL AND ca.file != '' AND (ca.status IS NULL OR ca.status != 'CLOSED') $plantClause $fpmClause
        UNION ALL
        SELECT pa.id AS id FROM fpm_preventive_action pa
        JOIN fpm_header h ON h.id_fpm = pa.id_fpm
        WHERE pa.file IS NOT NULL AND pa.file != '' AND (pa.status IS NULL OR pa.status != 'CLOSED') $plantClause $fpmClause
        UNION ALL
        SELECT rd.id AS id FROM fpm_review_dokumen rd
        JOIN fpm_header h ON h.id_fpm = rd.id_fpm
        WHERE rd.file IS NOT NULL AND rd.file != '' AND (rd.status IS NULL OR rd.status != 'CLOSED') $plantClause $fpmClause
    ) t");
    $fpmCount = (int) (mysqli_fetch_assoc($qFpm)['cnt'] ?? 0);

    return ['asakai' => $asakaiCount, 'fpm' => $fpmCount, 'total' => $asakaiCount + $fpmCount];
}

/**
 * @return list<array<string, mixed>>
 */
function fetchPendingAsakaiVerifikasi(mysqli $koneksi, string $plant): array
{
    $plantClause = buildVerifikasiPlantClause($koneksi, $plant, 'h.plant');

    $query = mysqli_query($koneksi, "SELECT
            h.id_asakai, h.tanggal, h.plant, h.machine, h.namapart, h.partnumber,
            'DOWN_TIME' AS kategori,
            cd.id_cm_dt AS id_cm, cd.deskripsi, cd.pic, cd.due_date, cd.actual_date, cd.status
        FROM asakai_countermeasure_downtime cd
        JOIN asakai_header h ON h.id_asakai = cd.id_asakai
        WHERE (NULLIF(cd.file1,'') IS NOT NULL OR NULLIF(cd.file2,'') IS NOT NULL OR NULLIF(cd.file3,'') IS NOT NULL)
          AND (cd.status IS NULL OR cd.status != 'CLOSED')
          $plantClause

        UNION ALL

        SELECT
            h.id_asakai, h.tanggal, h.plant, h.machine, h.namapart, h.partnumber,
            'NG' AS kategori,
            cn.id_cm_ng AS id_cm, cn.deskripsi, cn.pic, cn.due_date, cn.actual_date, cn.status
        FROM asakai_countermeasure_ng cn
        JOIN asakai_header h ON h.id_asakai = cn.id_asakai
        WHERE (NULLIF(cn.file1,'') IS NOT NULL OR NULLIF(cn.file2,'') IS NOT NULL OR NULLIF(cn.file3,'') IS NOT NULL)
          AND (cn.status IS NULL OR cn.status != 'CLOSED')
          $plantClause

        ORDER BY tanggal DESC
    ");

    $rows = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function fetchPendingFpmVerifikasi(mysqli $koneksi, string $plant, bool $onlyClaimMach = false): array
{
    $plantClause = buildVerifikasiPlantClause($koneksi, $plant, 'h.plant');
    $fpmClause = buildVerifikasiFpmFlowClause($onlyClaimMach);

    $query = mysqli_query($koneksi, "SELECT
            h.id_fpm, h.no_form, h.no_reg_fpm, h.plant, h.jenis_fpm, h.nama_part, h.machine_no,
            'Corrective Action' AS jenis_aksi,
            ca.tindakan AS deskripsi, ca.pic, ca.due_date, ca.actual_date, ca.status
        FROM fpm_corrective_action ca
        JOIN fpm_header h ON h.id_fpm = ca.id_fpm
        WHERE ca.file IS NOT NULL AND ca.file != '' AND (ca.status IS NULL OR ca.status != 'CLOSED') $plantClause $fpmClause

        UNION ALL

        SELECT
            h.id_fpm, h.no_form, h.no_reg_fpm, h.plant, h.jenis_fpm, h.nama_part, h.machine_no,
            'Preventive Action' AS jenis_aksi,
            pa.tindakan AS deskripsi, pa.pic, pa.due_date, pa.actual_date, pa.status
        FROM fpm_preventive_action pa
        JOIN fpm_header h ON h.id_fpm = pa.id_fpm
        WHERE pa.file IS NOT NULL AND pa.file != '' AND (pa.status IS NULL OR pa.status != 'CLOSED') $plantClause $fpmClause

        UNION ALL

        SELECT
            h.id_fpm, h.no_form, h.no_reg_fpm, h.plant, h.jenis_fpm, h.nama_part, h.machine_no,
            'Dokumen' AS jenis_aksi,
            rd.dokumen AS deskripsi, rd.pic, rd.due_date, rd.actual_date, rd.status
        FROM fpm_review_dokumen rd
        JOIN fpm_header h ON h.id_fpm = rd.id_fpm
        WHERE rd.file IS NOT NULL AND rd.file != '' AND (rd.status IS NULL OR rd.status != 'CLOSED') $plantClause $fpmClause

        ORDER BY id_fpm DESC
    ");

    $rows = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }
    return $rows;
}
