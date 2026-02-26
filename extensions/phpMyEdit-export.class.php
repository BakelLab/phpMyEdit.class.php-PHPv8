<?php

// Use modern error reporting
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

/* * phpMyEdit-export.class.php
 * Updated for PHP 8.3 with Performance Hoisting and Memory Management
 */

$opts['ext']['exports'] = [
    'text'  => [],
    'csv'   => [
        'content-type' => 'text/csv', // Updated for standard CSV
        'filename'     => 'export.csv', // Added explicit filename
        'content'      => '$header$rows',
        'row'          => '$cells' . "\n",
        'cell'         => '"$value",', // Semicolon changed to comma, moved to end
        'value'        => ['strip_tags', 'addslashes'],
    ],
    'html'  => [
        'content-type' => 'text/html',
        'content'      => '<table>$header$rows</table>',
        'row'          => '<tr>$cells</tr>' . "\n",
        'cell-h'       => '<th>$value</th>',
        'cell'         => '<td>$value</td>',
        'value'        => ['strip_tags', 'htmlspecialchars', 'nl2br'],
    ],
    'excel' => [
        'content-type' => 'application/vnd.ms-excel',
        'filename'     => 'export.xls',
        'content'      => '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><table border="1">$header$rows</table></body></html>',
        'row'          => '<tr>$cells</tr>' . "\n",
        'cell'         => '<td>$value</td>',
        'value'        => ['strip_tags', 'htmlspecialchars'],
    ],
    'word' => [
        'content-type' => 'application/ms-word',
        'filename'     => 'export.doc',
        'content'      => '<html><body><table border="1">$header$rows</table></body></html>',
        'row'          => '<tr>$cells</tr>' . "\n",
        'cell'         => '<td>$value</td>',
        'value'        => ['strip_tags', 'htmlspecialchars', 'nl2br'],
    ],
];

require_once dirname(__FILE__) . '/../phpMyEdit.class.php';

class phpMyEdit_export extends phpMyEdit
{
    public $ext;

    public function __construct($opts)
    {
        $execute = $opts['execute'] ?? 1;
        $opts['execute'] = 0;
        $this->ext = $opts['ext'] ?? [];
        parent::__construct($opts);
        $this->ext['export'] = $this->get_sys_cgi_var('export');
        if ($execute) {
            $this->execute();
        }
    }

    public function display_list_table_buttons($position, $listall = false)
    {
        if (isset($this->ext['exports']) && is_array($this->ext['exports'])) {
            $qstrparts = [];
            $sys_prefix = $this->cgi['prefix']['sys'] ?? '';
            
            if (strlen((string)$this->fl) > 0) $qstrparts[] = $sys_prefix . 'fl=' . $this->fl;
            if (strlen((string)$this->fm) > 0) $qstrparts[] = $sys_prefix . 'fm=' . $this->fm;
            if (strlen((string)$this->qfn) > 0) $qstrparts[] = $this->qfn;
            if (is_array($this->sfn) && !empty($this->sfn)) $qstrparts[] = $this->get_sfn_cgi_vars();
            if (strlen((string)($this->cgi['persist'] ?? '')) > 0) $qstrparts[] = $this->cgi['persist'];

            $qstrparts[] = $sys_prefix . 'export=';
            $link_str    = htmlspecialchars($this->page_name . '?' . join('&', $qstrparts));
            $exports_str = '';

            foreach ($this->ext['exports'] as $name => $cfg) {
                $exports_str .= ($exports_str != '' ? '&nbsp;' : '');
                $exports_str .= '<a href="' . $link_str . $name . '">';
                $icon = $cfg['icon'] ?? "pme-export-{$name}.gif";
                $exports_str .= '<img src="' . ($this->url['images'] ?? '') . $icon . '" border="0" alt="' . $name . '" title="' . $name . '"></a>';
            }
        } else {
            $exports_str = 'no&nbsp;exports';
        }
        
        $message_ori = $this->message;
        $this->message = $exports_str . '</td><td>' . $this->message;
        parent::display_list_table_buttons($position);
        $this->message = $message_ori;
    }

    public function list_table()
    {
        if (!$this->export_operation()) {
            return parent::list_table();
        }

        // RESOURCE TUNING for large assemblies/lineages
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);

        $this->recreate_fdd();
        $this->recreate_displayed();
        $this->backward_compatibility();
        $this->gather_query_opts();

        if (ob_get_length()) ob_end_clean();

        $export_type = $this->ext['export'] ?? '';
        $cfg = $this->ext['exports'][$export_type] ?? [];

        // 1. DATA HOISTING: Pre-calculate column transformation map
        $trans_map = [];
        $header_cells = '';
        for ($k = 0; $k < $this->num_fds; $k++) {
            if (!($this->displayed[$k] ?? false)) continue;

            $fd = $this->fds[$k];
            $fdd_ext = $this->fdd[$fd]['ext'] ?? [];
            
            // Map metadata for data loop
            $trans_map[$k] = [
                'fd'    => $fd,
                'is_pw' => $this->password($k),
                'tmpl'  => $fdd_ext['cell'] ?? $cfg['cell'] ?? '$value',
                'funcs' => (array)($fdd_ext['value'] ?? $cfg['value'] ?? [])
            ];

            // Map metadata for header generation
            $h_val = $fdd_ext['name'] ?? $this->fdd[$fd]['name'] ?? $fd;
            $h_tmpl = $fdd_ext['cell-h'] ?? $cfg['cell-h'] ?? $trans_map[$k]['tmpl'];
            
            $header_cells .= $this->substituteVars($h_tmpl, ['value' => $h_val, 'name' => $h_val]);
        }

        $row_h_tmpl = $cfg['row-h'] ?? $cfg['row'] ?? "$cells\n";
        $export_header = $this->substituteVars($row_h_tmpl, ['cells' => rtrim($header_cells, ',')]);

        // 2. SQL OPTIMIZATION
        $qparts = [
            'type'   => 'select',
            'select' => $this->get_SQL_column_list(),
            'from'   => $this->get_SQL_join_clause(),
            'where'  => $this->get_SQL_where_from_query_opts(),
            'limit'  => '' // Exports usually ignore pagination
        ];

        if (isset($this->sfn) && is_array($this->sfn)) {
            $sort_fields = [];
            foreach ($this->sfn as $field) {
                $desc = str_starts_with((string)$field, '-');
                $actual_field = $desc ? substr($field, 1) : $field;
                $sort_fields[] = $this->fqn($actual_field) . ($desc ? ' DESC' : '');
            }
            if (!empty($sort_fields)) $qparts['orderby'] = join(',', $sort_fields);
        }

        $query = $this->get_SQL_query($qparts);
        $res   = $this->myquery($query, __LINE__);
        if (!$res) return false;

        // 3. ROW PROCESSING WITH BUFFERING
        $row_changed_cfg = $cfg['row-on-change'] ?? [];
        $row_changed_state = array_fill_keys(array_keys($row_changed_cfg), null);
        $row_tmpl_default = $cfg['row'] ?? "$cells\n";

        ob_start();
        while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
            $cells = '';
            $assoc = [];
            
            foreach ($trans_map as $k => $m) {
                $val = $m['is_pw'] ? ($this->labels['hidden'] ?? '****') : ($row["qf$k"] ?? '');
                foreach ($m['funcs'] as $fnc) {
                    if (is_callable($fnc)) $val = $fnc((string)$val);
                }
                $cells .= str_replace('$value', (string)$val, $m['tmpl']);
                $assoc[$m['fd']] = $val;
            }

            $assoc['cells'] = rtrim($cells, ',');
            $current_row_tmpl = $row_tmpl_default;

            foreach ($row_changed_cfg as $key => $change_tmpl) {
                if (($assoc[$key] ?? null) !== ($row_changed_state[$key] ?? null)) {
                    $current_row_tmpl = $change_tmpl;
                    $row_changed_state[$key] = $assoc[$key];
                }
            }
            echo $this->substituteVars($current_row_tmpl, $assoc);
        }
        $export_rows = ob_get_clean();

        // 4. FINAL DELIVERY
        $content_type = $cfg['content-type'] ?? 'text/plain';
        $filename = $cfg['filename'] ?? 'export.txt'; // This now pulls 'export.csv' from your config
        
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
            
        $final_tmpl = $cfg['content'] ?? '$header$rows';
        echo $this->substituteVars($final_tmpl, ['header' => $export_header, 'rows' => $export_rows]);
        exit;
    }

    public function export_operation()
    {
        return strlen((string)($this->ext['export'] ?? '')) > 0;
    }

    public function displayed($k)
    {
        if ($this->export_operation()) {
            $options = (string)($this->fdd[$k]['options'] ?? '');
            if ($options !== '') {
                return str_contains(strtoupper($options), 'X') || str_contains(strtoupper($options), 'L');
            }
            return true;
        }
        return parent::displayed($k);
    }
}
