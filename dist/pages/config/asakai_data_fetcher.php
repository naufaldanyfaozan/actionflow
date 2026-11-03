<?php

require_once __DIR__ . '/asakai_summary_helper.php';

/**
 * Re-runs the exact filter/query logic from list_asakai.php against $_GET,
 * for use by each role's export_asakai.php. Returns raw mysqli rows (not the
 * grouped/rendered shape) so asakai_excel_builder.php can do its own grouping.
 *
 * @param string|null $username Pass the session username only for roles whose
 *                               list_asakai.php defaults plant by username
 *                               (currently just staff_imp's doni/aji rule).
 * @return array{0: array, 1: array, 2: array, 3: string} [$mainRows, $deliveryRows, $castingRows, $tanggal]
 */
function fetchAsakaiExportData(mysqli $koneksi, ?string $username = null): array
{
    if (empty($_GET['plant']) && $username !== null) {
        if ($username === 'doni') {
            $_GET['plant'] = '1';
        } elseif ($username === 'aji') {
            $_GET['plant'] = '3';
        }
    }

    if (!isset($_GET['tgl_awal']) || $_GET['tgl_awal'] == '') {
        $_GET['tgl_awal'] = date('Y-m-d');
    }

    if (!isset($_GET['tgl_akhir']) || $_GET['tgl_akhir'] == '') {
        $_GET['tgl_akhir'] = date('Y-m-d');
    }

    $conditions = [];

    if (!empty($_GET['tgl_awal']) && !empty($_GET['tgl_akhir'])) {
        $tglAwalObj  = DateTime::createFromFormat('Y-m-d', $_GET['tgl_awal']);
        $tglAkhirObj = DateTime::createFromFormat('Y-m-d', $_GET['tgl_akhir']);
        if ($tglAwalObj && $tglAkhirObj) {
            $tglAwalDb  = $tglAwalObj->format('Y-m-d');
            $tglAkhirDb = $tglAkhirObj->format('Y-m-d');
            $conditions[] = "h.tanggal BETWEEN '$tglAwalDb' AND '$tglAkhirDb'";
        }
    }

    if (!empty($_GET['plant'])) {
        $plantInput = mysqli_real_escape_string($koneksi, $_GET['plant']);
        $conditions[] = "h.plant = '$plantInput'";
    }

    if (!empty($_GET['machine'])) {
        $machine = mysqli_real_escape_string($koneksi, $_GET['machine']);
        $conditions[] = "h.machine = '$machine'";
    }

    if (!empty($_GET['pic'])) {
        $pic = mysqli_real_escape_string($koneksi, $_GET['pic']);
        $conditions[] = "(
            cd.pic = '$pic'
            OR
            cn.pic = '$pic'
        )";
    }

    if (!empty($_GET['problem'])) {
        $problem = mysqli_real_escape_string($koneksi, $_GET['problem']);
        $conditions[] = "(
            h.downtime_problem LIKE '%$problem%'
            OR
            h.ng_problem LIKE '%$problem%'
            OR
            EXISTS (SELECT 1 FROM asakai_ng_problem anp WHERE anp.id_asakai = h.id_asakai AND anp.deskripsi LIKE '%$problem%')
        )";
    }

    $conditions = array_merge($conditions, kondisiFilterTambahanAsakai($koneksi, $_GET));

    $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = mysqli_query($koneksi, "SELECT
            h.id_asakai,
            h.tanggal,
            h.plant,
            h.machine,
            h.namapart,
            h.type,
            h.partnumber,
            h.ok_qty,
            h.ng_qty,
            h.efficiency,
            h.downtime,
            h.target,
            h.standard,
            h.actual,
            h.persen_ng,

            cd.file1 AS file1_downtime,
            cd.file2 AS file2_downtime,
            cd.file3 AS file3_downtime,

            cn.file1 AS file1_ng,
            cn.file2 AS file2_ng,
            cn.file3 AS file3_ng,

            a.kategori,
            CONCAT_WS('<br>', a.analisa1, '.',a.analisa2, '.',a.analisa3, '.',a.analisa4) AS analisa,

            CASE
                WHEN a.kategori = 'DOWN_TIME' THEN h.downtime_problem
                WHEN a.kategori = 'NG' THEN TRIM(BOTH '\n' FROM CONCAT_WS('\n', NULLIF(h.ng_problem, ''), ngp.extra_ng))
            END AS problem,

            cd.id_cm_dt AS id_cm_dt,
            cd.deskripsi AS cm_downtime,
            cd.pic AS pic_downtime,
            cd.due_date AS due_downtime,
            cd.status AS status_downtime,

            cn.id_cm_ng AS id_cm_ng,
            cn.deskripsi AS cm_ng,
            cn.pic AS pic_ng,
            cn.due_date AS due_ng,
            cn.status AS status_ng

        FROM asakai_header h
        INNER JOIN asakai_analisa a
            ON a.id_asakai = h.id_asakai
        LEFT JOIN (
            SELECT id_asakai, GROUP_CONCAT(deskripsi SEPARATOR '\n') AS extra_ng
            FROM asakai_ng_problem
            GROUP BY id_asakai
        ) ngp ON ngp.id_asakai = h.id_asakai
        LEFT JOIN asakai_countermeasure_downtime cd
            ON cd.id_asakai = h.id_asakai
            AND a.kategori = 'DOWN_TIME'
        LEFT JOIN asakai_countermeasure_ng cn
            ON cn.id_asakai = h.id_asakai
            AND a.kategori = 'NG'
        $whereClause
        ORDER BY
            h.tanggal DESC,
            h.machine ASC,
            a.kategori ASC
    ");

    $mainRows = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $mainRows[] = $row;
    }

    $tglFilterDelivery = mysqli_real_escape_string($koneksi, $_GET['tgl_akhir']);

    $queryDelivery = mysqli_query($koneksi, "SELECT
        m.nama_part,
        m.part_number,
        mp.type AS part_type,
        m.act_stock,
        m.delivery_per_hari,
        m.sisa_stock,
        m.news_delivery,
        c.countermeasure,
        c.due_date,
        c.pic
    FROM `fpm_delivery_monitor` m
    LEFT JOIN `master_part` mp ON mp.partnumber = m.part_number
    LEFT JOIN `fpm_delivery_monitor_countermeasure` c
        ON m.id_monitor = c.id_monitor
    WHERE m.tanggal = '$tglFilterDelivery'" . kondisiFilterPartStock($koneksi, $_GET, 'm.nama_part', 'm.part_number') . "
    ORDER BY m.id_monitor ASC");

    $deliveryRows = [];
    while ($row = mysqli_fetch_assoc($queryDelivery)) {
        $deliveryRows[] = $row;
    }

    $queryCasting = mysqli_query($koneksi, "SELECT
            sc.id_casting,
            sc.part_number,
            sc.nama_part,
            mp.type AS part_type,
            sc.dcm,
            sc.min_stock,
            sc.stock_ppc
        FROM stock_casting_monitor sc
        LEFT JOIN master_part mp ON mp.partnumber = sc.part_number
        WHERE sc.tanggal = '$tglFilterDelivery'" . kondisiFilterPartStock($koneksi, $_GET, 'sc.nama_part', 'sc.part_number') . "
        ORDER BY sc.id_casting ASC
    ");

    $castingRows = [];
    while ($row = mysqli_fetch_assoc($queryCasting)) {
        $castingRows[] = $row;
    }

    return [$mainRows, $deliveryRows, $castingRows, $_GET['tgl_akhir']];
}
