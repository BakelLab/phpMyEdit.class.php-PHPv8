<?php

// Use modern error reporting
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

/* [Header comments preserved] */

$opts['ext']['exports'] = array(
    'text'  => array(),
    'csv'   => array(
        'content-type' => 'text/plain',
        'content'      => '$header$rows',
        'row'          => '$cells'."\n",
        'cell'         => ';"$value"',
        'value'        => array('strip_tags', 'addslashes')
    ),
    'html'  => array(
        'content-type' => 'text/html',
        'content'      => '<table>$header$rows</table>',
        'row'          => '<tr>$cells</tr>'."\n",
        'cell-h'       => '<th>$value</th>',
        'cell'         => '<td>$value</td>',
        'value'        => array('strip_tags', 'htmlspecialchars', 'nl2br')
    ),
    'excel' => array(
        'content-type' => 'application/vnd.ms-excel',
        'filename'     => 'export.xls',
        'content'      => '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><table border="1">$header$rows</table></body></html>',
        'row'          => '<tr>$cells</tr>'."\n",
        'cell'         => '<td>$value</td>',
        'value'        => array('strip_tags', 'htmlspecialchars')
    ),
    'word' => array(
        'content-type' => 'application/ms-word',
        'filename'     => 'export.doc',
        'content'      => '<html><body><table border="1">$header$rows</table></body></html>',
        'row'          => '<tr>$cells</tr>'."\n",
        'cell'         => '<td>$value</td>',
        'value'        => array('strip_tags', 'htmlspecialchars', 'nl2br')
    ),
);

require_once dirname(__FILE__).'/../phpMyEdit.class.php';

class phpMyEdit_export extends phpMyEdit
{
    var $ext;

    // PHP 8+ uses __construct instead of the class name
    function __construct($opts)
    {
        $execute = $opts['execute'] ?? 1;
        $opts['execute'] = 0;
        $this->ext = $opts['ext'] ?? [];
        parent::__construct($opts); // Call parent constructor
        $this->ext['export'] = $this->get_sys_cgi_var('export');
        if ($execute) {
            $this->execute();
        }
    }

    function display_list_table_buttons($position, $listall = false)
    {
        if (isset($this->ext['exports']) && is_array($this->ext['exports'])) {
            $qstrparts = array();
            (strlen((string)$this->fl) > 0) && $qstrparts[] = ($this->cgi['prefix']['sys'] ?? '').'fl='.$this->fl;
            (strlen((string)$this->fm) > 0) && $qstrparts[] = ($this->cgi['prefix']['sys'] ?? '').'fm='.$this->fm;
            (strlen((string)$this->qfn) > 0) && $qstrparts[] = $this->qfn;
            (is_array($this->sfn) && count($this->sfn) > 0) && $qstrparts[] = $this->get_sfn_cgi_vars();
            (strlen((string)($this->cgi['persist'] ?? '')) > 0) && $qstrparts[] = $this->cgi['persist'];
            
            $qstrparts[] = ($this->cgi['prefix']['sys'] ?? '').'export=';
            $link_str    = htmlspecialchars($this->page_name.'?'.join('&', $qstrparts));
            $exports_str = '';

            foreach ($this->ext['exports'] as $name => $cfg) {
                $exports_str .= ($exports_str != '' ? '&nbsp;' : '');
                $exports_str .= '<a href="'.$link_str.$name.'">';
                
                $icon = $cfg['icon'] ?? "pme-export-{$name}.gif";
                $exports_str .= '<img src="'.($this->url['images'] ?? '').$icon.'" border="0" alt="'.$name.'" title="'.$name.'">';
                $exports_str .= '</a>';
            }
        } else {
            $exports_str = 'no&nbsp;exports';
        }
        $message_ori = $this->message;
        $this->message = $exports_str.'</td><td>'.$this->message;
        parent::display_list_table_buttons($position);
        $this->message = $message_ori;
    }

    function list_table()
    {
        if (! $this->export_operation()) {
            return parent::list_table();
        }

        $this->recreate_fdd();
        $this->recreate_displayed();
        $this->backward_compatibility();
        $this->gather_query_opts();

        // Ensure we don't have previous output
        if (ob_get_length()) ob_end_clean();

        $export_header = '';
        $export_rows   = '';
        $export_type   = $this->ext['export'] ?? '';
        
        if ($this->fm == '') $this->fm = 0;
        $listall = 1;

        // Header Generation
        $cells = '';
        for ($k = 0; $k < $this->num_fds; $k++) {
            if (! ($this->displayed[$k] ?? false)) continue;

            $fd = $this->fds[$k];
            $value = $this->fdd[$fd]['ext']['name'] ?? $this->fdd[$fd]['name'] ?? 'no-name';
            
            // Cascading lookup for templates
            $tmpl = $this->fdd[$fd]['ext']['cell-h'] 
                 ?? $this->ext['exports'][$export_type]['cell-h'] 
                 ?? $this->fdd[$fd]['ext']['cell'] 
                 ?? $this->ext['exports'][$export_type]['cell'] 
                 ?? '[$value]';

            $func = $this->fdd[$fd]['ext']['value'] 
                 ?? $this->ext['exports'][$export_type]['value'] 
                 ?? null;

            if (is_array($func)) {
                foreach ($func as $fnc) if (is_callable($fnc)) $value = $fnc($value);
            } elseif (is_callable($func)) {
                $value = $func($value);
            }

            $cells .= $this->substituteVars($tmpl, array('value' => $value, 'name' => $value));
        }

        $row_tmpl = $this->fdd[$fd]['ext']['row-h'] 
                 ?? $this->ext['exports'][$export_type]['row-h'] 
                 ?? $this->fdd[$fd]['ext']['row'] 
                 ?? $this->ext['exports'][$export_type]['row'] 
                 ?? "HEADER: \$cells\n";
        
        $export_header = $this->substituteVars($row_tmpl, array('cells' => $cells));

        // Prepare "On Change" tracking
        $row_changed_cfg = $this->ext['exports'][$export_type]['row-on-change'] ?? [];
        $row_changed_state = array_fill_keys(array_keys($row_changed_cfg), null);

        // SQL Preparation
        $qparts['type']   = 'select';
        $qparts['select'] = $this->get_SQL_column_list();
        $qparts['from']   = $this->get_SQL_join_clause();
        $qparts['where']  = $this->get_SQL_where_from_query_opts();
        
        if (isset($this->sfn) && is_array($this->sfn)) {
            $sort_fields = array();
            foreach ($this->sfn as $field) {
                $desc = str_starts_with($field, '-');
                $actual_field = $desc ? substr($field, 1) : $field;
                $sort_fields[] = $this->fqn($actual_field) . ($desc ? ' DESC' : '');
            }
            if (count($sort_fields) > 0) $qparts['orderby'] = join(',', $sort_fields);
        }
        $qparts['limit'] = $listall ? '' : $this->fm.','.$this->inc;

        $query = $this->get_SQL_query($qparts);
        $res   = $this->myquery($query, __LINE__);
        
        if (!$res) {
            $this->error('invalid SQL query', $query);
            return false;
        }

        // Data Row Processing
        while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
            $cells = '';
            for ($k = 0; $k < $this->num_fds; $k++) {
                if (! ($this->displayed[$k] ?? false)) continue;
                
                $fd = $this->fds[$k];
                $value = ($this->password($k)) ? ($this->labels['hidden'] ?? '****') : ($row["qf$k"] ?? '');
                
                $tmpl = $this->fdd[$fd]['ext']['cell'] ?? $this->ext['exports'][$export_type]['cell'] ?? '($value)';
                $func = $this->fdd[$fd]['ext']['value'] ?? $this->ext['exports'][$export_type]['value'] ?? null;

                if (is_array($func)) {
                    foreach ($func as $fnc) if (is_callable($fnc)) $value = $fnc((string)$value);
                } elseif (is_callable($func)) {
                    $value = $func((string)$value);
                }
                $cells .= $this->substituteVars($tmpl, array('value' => $value));
            }

            $assoc = array('cells' => $cells);
            foreach ($row as $key => $val) {
                if (preg_match('/^qf(\d+)$/', $key, $matches)) {
                    $idx = $matches[1];
                    if (isset($this->fds[$idx])) $assoc[$this->fds[$idx]] = $val;
                }
            }

            $tmpl = $this->fdd[$fd]['ext']['row'] ?? $this->ext['exports'][$export_type]['row'] ?? "ROW: \$cells\n";
            
            // Check for row-on-change logic
            foreach ($row_changed_cfg as $key => $change_tmpl) {
                if (($assoc[$key] ?? null) !== ($row_changed_state[$key] ?? null)) {
                    $tmpl = $change_tmpl;
                    $row_changed_state[$key] = $assoc[$key];
                }
            }
            $export_rows .= $this->substituteVars($tmpl, $assoc);
        }

        // Final Output
        $content_tmpl = $this->ext['exports'][$export_type]['content'] ?? "\$header\n\$rows";
        $content_type = $this->ext['exports'][$export_type]['content-type'] ?? 'text/plain';
        $filename = $this->ext['exports'][$export_type]['filename'] ?? null;

        header('Content-Type: ' . $content_type);
        if ($filename) {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        echo $this->substituteVars($content_tmpl, array('header' => $export_header, 'rows' => $export_rows));
        exit;
    }

    function export_operation()
    {
        return strlen((string)($this->ext['export'] ?? '')) > 0;
    }

    function displayed($k)
    {
        if ($this->export_operation()) {
            $options = $this->fdd[$k]['options'] ?? null;
            if ($options !== null) {
                return stristr((string)$options, 'X') !== false;
            }
            return true;
        }
        return parent::displayed($k);
    }
}
