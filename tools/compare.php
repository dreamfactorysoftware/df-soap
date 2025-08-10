<?php
// compare.php - Standalone WSDL introspection and tree viewer
// Purpose: Parse a WSDL file (default: test_wsdl.xml) to extract all schema elements and types
// and provide: (1) JSON for AJAX, and (2) a nice JS tree view page consuming that JSON.

// Ensure errors are visible during local development
ini_set('display_errors', '1');
error_reporting(E_ALL);
// Buffer output so we can discard any notices/warnings for JSON responses
ob_start();

// Utility: determine requested action
$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';
$wantJson = ($format === 'json') || isset($_GET['json']);

// Resolve WSDL path: allow ?wsdl=<path>, else default to test_wsdl.xml in this directory
$wsdlParam = isset($_GET['wsdl']) ? (string)$_GET['wsdl'] : 'test_wsdl.xml';
$wsdlPath = $wsdlParam;
if (!preg_match('~^[a-zA-Z]+://~', $wsdlPath)) { // not a URL
    if (!str_starts_with($wsdlPath, DIRECTORY_SEPARATOR) && !preg_match('~^[A-Za-z]:[\\/]~', $wsdlPath)) {
        // relative path -> resolve relative to this file directory
        $wsdlPath = __DIR__ . DIRECTORY_SEPARATOR . $wsdlPath;
    }
}

// Basic helpers
function respond_json($data, $status = 200) {
    http_response_code($status);
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_json($message, $status = 400) {
    respond_json(['error' => $message], $status);
}

// Parse WSDL -> Extract schemas, elements, complexTypes, simpleTypes
function parse_wsdl_schemas($wsdlPath) {
    if (empty($wsdlPath)) {
        throw new RuntimeException('WSDL path not provided.');
    }

    // Load XML
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->load($wsdlPath);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) {
        $msg = 'Failed to load WSDL: ' . $wsdlPath;
        if (!empty($errors)) {
            $first = $errors[0];
            $msg .= sprintf(' (Line %d: %s)', $first->line ?? 0, trim($first->message ?? 'unknown error'));
        }
        throw new RuntimeException($msg);
    }

    $xp = new DOMXPath($dom);
    // Register common namespaces (best-effort; we will primarily use local-name() in queries)
    $xp->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
    $xp->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

    $targetNs = '';
    $defs = $xp->query('/*[local-name()="definitions"]');
    if ($defs && $defs->length) {
        /** @var DOMElement $def */
        $def = $defs->item(0);
        $targetNs = $def->getAttribute('targetNamespace');
    }

    // Gather all <schema> under <types>
    $schemaNodes = $xp->query('/*[local-name()="definitions"]/*[local-name()="types"]/*[local-name()="schema"]');

    $elements = [];
    $complexTypes = [];
    $simpleTypes = [];
    $schemas = [];

    // Define first so the closure can recursively reference itself
    $extractElementInfo = null;
    $extractElementInfo = function (DOMElement $el, DOMXPath $xp) use (&$extractElementInfo) {
        $info = [
            'name'       => $el->getAttribute('name'),
            'type'       => $el->getAttribute('type') ?: null,
            'ref'        => $el->getAttribute('ref') ?: null,
            'minOccurs'  => $el->getAttribute('minOccurs') ?: null,
            'maxOccurs'  => $el->getAttribute('maxOccurs') ?: null,
            'nillable'   => $el->getAttribute('nillable') ?: null,
            'children'   => [],
            'inlineType' => null,
        ];

        // Check for inline complexType/simpleType directly under this element
        $inlineCt = $xp->query('./*[local-name()="complexType"]', $el);
        if ($inlineCt && $inlineCt->length) {
            $info['inlineType'] = 'complexType(inline)';
            // Support sequence/all/choice
            $content = $xp->query('./*[local-name()="complexType"]/*[local-name()="sequence" or local-name()="all" or local-name()="choice"]', $el);
            if ($content && $content->length) {
                $container = $content->item(0);
                $subEls = $xp->query('./*[local-name()="element"]', $container);
                foreach ($subEls as $se) {
                    $info['children'][] = $extractElementInfo($se, $xp);
                }
            }
        } else {
            $inlineSt = $xp->query('./*[local-name()="simpleType"]', $el);
            if ($inlineSt && $inlineSt->length) {
                $info['inlineType'] = 'simpleType(inline)';
            }
        }

        return $info;
    };

    $extractComplexType = function (DOMElement $ct, DOMXPath $xp) use (&$extractElementInfo) {
        $name = $ct->getAttribute('name') ?: null;
        $extends = null;
        $contentNode = null; // sequence/all/choice

        // Check for complexContent/extension
        $extNode = $xp->query('./*[local-name()="complexContent"]/*[local-name()="extension"]', $ct);
        if ($extNode && $extNode->length) {
            /** @var DOMElement $ext */
            $ext = $extNode->item(0);
            $extends = $ext->getAttribute('base') ?: null;
            // content may be under the extension
            $cnode = $xp->query('./*[local-name()="sequence" or local-name()="all" or local-name()="choice"]', $ext);
            if ($cnode && $cnode->length) {
                $contentNode = $cnode->item(0);
            }
        }
        if (!$contentNode) {
            // direct content under complexType
            $cnode = $xp->query('./*[local-name()="sequence" or local-name()="all" or local-name()="choice"]', $ct);
            if ($cnode && $cnode->length) {
                $contentNode = $cnode->item(0);
            }
        }

        $fields = [];
        if ($contentNode instanceof DOMElement) {
            $childEls = $xp->query('./*[local-name()="element"]', $contentNode);
            foreach ($childEls as $child) {
                /** @var DOMElement $child */
                $f = $extractElementInfo($child, $xp);

                // Inline type? capture brief shape
                if (!$f['type']) {
                    // Try to detect inline complexType/simpleType under this element
                    $inlineCt = $xp->query('./*[local-name()="complexType"]', $child);
                    if ($inlineCt && $inlineCt->length) {
                        $f['inlineType'] = 'complexType(inline)';
                        // Optional: extract the inner sequence fields shallowly
                        $seq2 = $xp->query('.//*[local-name()="sequence" or local-name()="all" or local-name()="choice"]', $inlineCt->item(0));
                        if ($seq2 && $seq2->length) {
                            $seq2Node = $seq2->item(0);
                            $subEls = $xp->query('./*[local-name()="element"]', $seq2Node);
                            foreach ($subEls as $se) {
                                $f['children'][] = $extractElementInfo($se, $xp);
                            }
                        }
                    } else {
                        $inlineSt = $xp->query('./*[local-name()="simpleType"]', $child);
                        if ($inlineSt && $inlineSt->length) {
                            $f['inlineType'] = 'simpleType(inline)';
                        }
                    }
                }

                $fields[] = $f;
            }
        }

        return [
            'name'    => $name,
            'extends' => $extends,
            'fields'  => $fields,
        ];
    };

    $extractSimpleType = function (DOMElement $st, DOMXPath $xp) {
        $name = $st->getAttribute('name') ?: null;
        $restrictionBase = null;
        $enumerations = [];

        $restr = $xp->query('./*[local-name()="restriction"]', $st);
        if ($restr && $restr->length) {
            /** @var DOMElement $r */
            $r = $restr->item(0);
            $restrictionBase = $r->getAttribute('base') ?: null;
            $enums = $xp->query('./*[local-name()="enumeration"]', $r);
            foreach ($enums as $e) {
                /** @var DOMElement $e */
                $val = $e->getAttribute('value');
                if ($val !== '') {
                    $enumerations[] = $val;
                }
            }
        }

        return [
            'name'        => $name,
            'base'        => $restrictionBase,
            'enumeration' => $enumerations,
        ];
    };

    foreach ($schemaNodes as $schema) {
        /** @var DOMElement $schema */
        $schemaTns = $schema->getAttribute('targetNamespace');
        $schemas[] = [
            'targetNamespace' => $schemaTns,
        ];

        // Top-level elements directly under this schema
        $els = $xp->query('./*[local-name()="element"]', $schema);
        foreach ($els as $el) {
            $elements[] = $extractElementInfo($el, $xp);
        }

        // complexTypes under this schema
        $cts = $xp->query('./*[local-name()="complexType"]', $schema);
        foreach ($cts as $ct) {
            $complexTypes[] = $extractComplexType($ct, $xp);
        }

        // simpleTypes under this schema
        $sts = $xp->query('./*[local-name()="simpleType"]', $schema);
        foreach ($sts as $st) {
            $simpleTypes[] = $extractSimpleType($st, $xp);
        }

        // Some WSDLs nest types further; also look for complexType/simpleType under global children like element/complexType
        $cts2 = $xp->query('.//*[local-name()="complexType" and @name]', $schema);
        foreach ($cts2 as $ct) {
            // Avoid duplicates by checking if already captured by name
            /** @var DOMElement $ct */
            $n = $ct->getAttribute('name');
            if ($n === '') continue;
            $exists = false;
            foreach ($complexTypes as $c) {
                if ($c['name'] === $n) { $exists = true; break; }
            }
            if (!$exists) {
                $complexTypes[] = $extractComplexType($ct, $xp);
            }
        }

        $sts2 = $xp->query('.//*[local-name()="simpleType" and @name]', $schema);
        foreach ($sts2 as $st) {
            /** @var DOMElement $st */
            $n = $st->getAttribute('name');
            if ($n === '') continue;
            $exists = false;
            foreach ($simpleTypes as $s) {
                if ($s['name'] === $n) { $exists = true; break; }
            }
            if (!$exists) {
                $simpleTypes[] = $extractSimpleType($st, $xp);
            }
        }
    }

    // Build lookup for complex types by local name
    $ctByName = [];
    foreach ($complexTypes as $ct) {
        if (!empty($ct['name'])) {
            $ctByName[$ct['name']] = $ct;
        }
    }

    // Helper: resolve a QName like ns:Name to local name
    $qname_local = function ($q) {
        if (!$q) return null;
        $pos = strpos($q, ':');
        return ($pos === false) ? $q : substr($q, $pos + 1);
    };

    // Helper: expand a complexType's fields including inherited base types via extension
    $get_complex_fields = null;
    $get_complex_fields = function ($name, array $visited = []) use (&$ctByName, &$qname_local, &$get_complex_fields) {
        if (!$name || !isset($ctByName[$name])) return [];
        if (in_array($name, $visited, true)) return [];
        $ct = $ctByName[$name];
        $fields = $ct['fields'] ?? [];
        $base = isset($ct['extends']) ? $qname_local($ct['extends']) : null;
        if ($base) {
            $baseFields = $get_complex_fields($base, array_merge($visited, [$name]));
            // Prepend base fields so derived can override by name if needed
            $fields = array_merge($baseFields, $fields);
        }
        return $fields;
    };

    // Recursively resolve fields with referenced complexTypes
    $resolve_fields = function (array &$fields, $depth = 3, array $visited = []) use (&$ctByName, &$qname_local, &$resolve_fields) {
        if ($depth <= 0) return;
        foreach ($fields as &$f) {
            if (!empty($f['type']) && empty($f['children'])) {
                $lname = $qname_local($f['type']);
                if ($lname && isset($ctByName[$lname]) && !in_array($lname, $visited, true)) {
                    $f['children'] = $ctByName[$lname]['fields'] ?? [];
                    $resolve_fields($f['children'], $depth - 1, array_merge($visited, [$lname]));
                }
            }
        }
    };

    // Resolve children for top-level elements based on their type
    foreach ($elements as &$el) {
        if (empty($el['children']) && !empty($el['type'])) {
            $lname = $qname_local($el['type']);
            if ($lname && isset($ctByName[$lname])) {
                $el['children'] = $ctByName[$lname]['fields'] ?? [];
                $resolve_fields($el['children']);
            }
        }        
    }
    unset($el);

    // Build lookup for elements by local name for quick resolution
    $elByName = [];
    foreach ($elements as $e) {
        if (!empty($e['name'])) {
            $elByName[$e['name']] = $e;
        }
    }

    // Parse wsdl:message definitions
    $messages = [];
    $msgByName = [];
    $msgNodes = $xp->query('/*[local-name()="definitions"]/*[local-name()="message"]');
    foreach ($msgNodes as $msg) {
        /** @var DOMElement $msg */
        $mName = $msg->getAttribute('name');
        $parts = [];
        $partNodes = $xp->query('./*[local-name()="part"]', $msg);
        foreach ($partNodes as $p) {
            /** @var DOMElement $p */
            $parts[] = [
                'name'    => $p->getAttribute('name') ?: null,
                'element' => $p->getAttribute('element') ?: null,
                'type'    => $p->getAttribute('type') ?: null,
            ];
        }
        $one = [ 'name' => $mName, 'parts' => $parts ];
        $messages[] = $one;
        if ($mName) $msgByName[$mName] = $one;
    }

    // Resolve a message to an effective wrapper element/type/fields
    $resolve_message = function (?string $messageName) use (&$msgByName, &$qname_local, &$elByName, &$get_complex_fields, &$resolve_fields) {
        $out = [
            'message' => $messageName,
            'element' => null,
            'type'    => null,
            'fields'  => [],
            'rawParts'=> null,
        ];
        if (!$messageName || !isset($msgByName[$messageName])) return $out;
        $msg = $msgByName[$messageName];
        $out['rawParts'] = $msg['parts'];
        // doc/literal wrapped: single part with @element
        if (count($msg['parts']) === 1 && !empty($msg['parts'][0]['element'])) {
            $elQName = $msg['parts'][0]['element'];
            $elName = $qname_local($elQName);
            $out['element'] = $elName;
            if ($elName && isset($elByName[$elName])) {
                $el = $elByName[$elName];
                // If wrapper has a single child with a type, treat that as the effective body type
                if (!empty($el['children']) && count($el['children']) === 1 && !empty($el['children'][0]['type'])) {
                    $child = $el['children'][0];
                    $tname = $qname_local($child['type']);
                    $out['type'] = $tname;
                    $fields = $get_complex_fields($tname);
                    // As a fallback, if no fields returned, use any pre-resolved children
                    if (empty($fields) && !empty($child['children'])) {
                        $fields = $child['children'];
                    }
                    $resolve_fields($fields);
                    $out['fields'] = $fields;
                } else {
                    // Element has its own type or inline children
                    if (!empty($el['type'])) {
                        $tname = $qname_local($el['type']);
                        $out['type'] = $tname;
                        $fields = $get_complex_fields($tname);
                        $resolve_fields($fields);
                        $out['fields'] = $fields;
                    } else {
                        $out['fields'] = $el['children'] ?? [];
                    }
                }
            }
        } else {
            // multi-part or type-based message; leave rawParts to inspect
        }
        return $out;
    };

    // Parse wsdl:portType operations and resolve to shapes
    $operations = [];
    $opNodes = $xp->query('/*[local-name()="definitions"]/*[local-name()="portType"]/*[local-name()="operation"]');
    foreach ($opNodes as $op) {
        /** @var DOMElement $op */
        $opName = $op->getAttribute('name');
        $inNode = $xp->query('./*[local-name()="input"]', $op); $inMsg = null;
        if ($inNode && $inNode->length) { $inMsg = $inNode->item(0)->getAttribute('message') ?: null; }
        $outNode = $xp->query('./*[local-name()="output"]', $op); $outMsg = null;
        if ($outNode && $outNode->length) { $outMsg = $outNode->item(0)->getAttribute('message') ?: null; }
        $inMsgLocal = $qname_local($inMsg);
        $outMsgLocal = $qname_local($outMsg);
        $operations[] = [
            'name'  => $opName,
            'input' => $resolve_message($inMsgLocal),
            'output'=> $resolve_message($outMsgLocal),
        ];
    }

    // Sort for stable display
    usort($elements, function ($a, $b) { return strcmp($a['name'] ?? '', $b['name'] ?? ''); });
    usort($complexTypes, function ($a, $b) { return strcmp($a['name'] ?? '', $b['name'] ?? ''); });
    usort($simpleTypes, function ($a, $b) { return strcmp($a['name'] ?? '', $b['name'] ?? ''); });
    usort($operations, function ($a, $b) { return strcmp($a['name'] ?? '', $b['name'] ?? ''); });

    return [
        'summary' => [
            'wsdl'            => $wsdlPath,
            'targetNamespace' => $targetNs,
            'schemaCount'     => count($schemas),
            'counts'          => [
                'elements'     => count($elements),
                'complexTypes' => count($complexTypes),
                'simpleTypes'  => count($simpleTypes),
                'operations'   => count($operations),
            ],
        ],
        'elements'     => $elements,
        'complexTypes' => $complexTypes,
        'simpleTypes'  => $simpleTypes,
        'operations'   => $operations,
    ];
}

if ($wantJson) {
    // Avoid polluting JSON with warnings
    ini_set('display_errors', '0');
    try {
        if (!file_exists($wsdlPath) && !preg_match('~^[a-zA-Z]+://~', $wsdlPath)) {
            fail_json('WSDL not found: ' . $wsdlPath, 404);
        }
        $data = parse_wsdl_schemas($wsdlPath);
        respond_json($data);
    } catch (Throwable $e) {
        fail_json($e->getMessage(), 500);
    }
}

// HTML UI
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">. and
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WSDL Schema Explorer</title>
  <style>
    :root {
      --bg: #0f172a;
      --panel: #111827;
      --panel2: #0b1220;
      --text: #e5e7eb;
      --muted: #9ca3af;
      --accent: #3b82f6;
      --border: #22314d;
    }
    html, body { height: 100%; }
    body {
      margin: 0; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Ubuntu, 'Helvetica Neue', Arial, 'Noto Sans', 'Apple Color Emoji', 'Segoe UI Emoji', sans-serif;
    }
    header {
      padding: 14px 18px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #101a2f 0%, var(--panel) 100%);
      position: sticky; top: 0; z-index: 10;
    }
    h1 { font-size: 18px; margin: 0 0 10px; font-weight: 600; letter-spacing: .2px; }
    .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    input[type="text"] {
      background: var(--panel2); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; min-width: 280px; outline: none;
    }
    input[type="text"]:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
    button {
      background: var(--accent); color: white; border: none; border-radius: 8px; padding: 10px 14px; cursor: pointer; font-weight: 600; letter-spacing: .2px;
    }
    button.secondary { background: #334155; }

    main { display: grid; grid-template-columns: 320px 1fr; height: calc(100vh - 74px); }
    aside { border-right: 1px solid var(--border); overflow: auto; background: #0c1426; }
    section { overflow: auto; }

    .panel { padding: 14px; }
    .title { font-size: 12px; text-transform: uppercase; color: var(--muted); margin: 10px 0 8px; letter-spacing: .6px; }

    /* Tree */
    .tree { list-style: none; margin: 0; padding-left: 14px; }
    .tree li { position: relative; padding-left: 18px; margin: 4px 0; }
    .tree li::before { content: ''; position: absolute; left: 6px; top: 10px; width: 8px; height: 1px; background: #2b3b5d; }
    .tree .node { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .tree .node .toggle { width: 12px; height: 12px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #2b3b5d; border-radius: 3px; font-size: 10px; color: #9fb5da; }
    .tree .children { margin-left: 0; padding-left: 14px; border-left: 1px dashed #22314d; }
    .muted { color: var(--muted); font-size: 12px; }
    .badge { background: #1f2937; color: #cbd5e1; border: 1px solid #2b3b5d; padding: 2px 6px; border-radius: 999px; font-size: 11px; }
    .badge.req { background: #7f1d1d; color: #fecaca; border-color: #ef4444; }

    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; border-bottom: 1px solid var(--border); padding: 10px; font-size: 14px; }
    th { position: sticky; top: 0; background: #0e1628; z-index: 1; }
    tr:hover td { background: #0d1a32; }

    .node.selected { background: #1c2942; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>
  <header>
    <h1>WSDL Schema Explorer</h1>
    <div class="row">
      <input type="text" id="wsdl-input" placeholder="WSDL path or URL" value="<?php echo htmlspecialchars($wsdlParam, ENT_QUOTES); ?>">
      <button id="load-btn">Load</button>
      <span class="muted">JSON endpoint: <span class="mono">compare.php?format=json&wsdl=...</span></span>
    </div>
  </header>

  <main>
    <aside>
      <div class="panel">
        <div class="title">Tree</div>
        <ul id="tree" class="tree"></ul>
      </div>
    </aside>
    <section>
      <div class="panel">
        <div class="title">Details</div>
        <div id="details"></div>
      </div>
    </section>
  </main>

  <script>
    const qs = new URLSearchParams(location.search);
    const input = document.getElementById('wsdl-input');
    const btn = document.getElementById('load-btn');
    const treeEl = document.getElementById('tree');
    const detailsEl = document.getElementById('details');

    btn.addEventListener('click', () => {
      const val = input.value.trim();
      if (!val) return;
      loadWSDL(val);
      const u = new URL(location.href);
      u.searchParams.set('wsdl', val);
      history.replaceState({}, '', u);
    });

    function fetchJson(wsdl) {
      const url = new URL(location.href);
      url.searchParams.set('format', 'json');
      url.searchParams.set('wsdl', wsdl);
      return fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        });
    }

    function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }

    function isRequired(x) {
      if (!x) return false;
      const raw = (x.minOccurs === undefined || x.minOccurs === null || x.minOccurs === '') ? '1' : String(x.minOccurs);
      const n = raw === 'unbounded' ? Infinity : Number(raw);
      return Number.isFinite(n) ? n >= 1 : false;
    }

    let selectedNodeEl = null;

    function makeNode(label, badge, payload = null) {
      const li = document.createElement('li');
      const node = document.createElement('div'); node.className = 'node';
      const toggle = document.createElement('span'); toggle.className = 'toggle'; toggle.textContent = '▸';
      const text = document.createElement('span'); text.textContent = label;
      node.appendChild(toggle); node.appendChild(text);
      if (badge) {
        const b = document.createElement('span');
        const isReq = (badge === 'req');
        b.className = 'badge' + (isReq ? ' req' : '');
        b.textContent = isReq ? '•' : String(badge);
        if (isReq) b.title = 'required (minOccurs>=1; default is 1)';
        node.appendChild(b);
      }
      const children = document.createElement('ul'); children.className = 'children'; children.style.display = 'none';
      node.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = children.style.display !== 'none';
        children.style.display = open ? 'none' : 'block';
        toggle.textContent = open ? '▸' : '▾';
        // selection + details
        if (selectedNodeEl) selectedNodeEl.classList.remove('selected');
        node.classList.add('selected');
        selectedNodeEl = node;
        if (payload) renderDetails(payload);
      });
      li.appendChild(node); li.appendChild(children);
      return { li, children, node };
    }

    function fieldLabel(f) {
      const parts = [];
      parts.push(f.name || '(anon)');
      if (f.type) parts.push(':' + f.type);
      if (f.inlineType) parts.push(' [' + f.inlineType + ']');
      const mm = [];
      if (f.minOccurs) mm.push('min=' + f.minOccurs);
      if (f.maxOccurs) mm.push('max=' + f.maxOccurs);
      if (f.nillable) mm.push('nillable=' + f.nillable);
      if (mm.length) parts.push(' (' + mm.join(', ') + ')');
      return parts.join(' ');
    }

    function renderTree(data) {
      clear(treeEl);

      const ops = Array.isArray(data.operations) ? data.operations : [];
      const root0 = makeNode('Operations', String(ops.length));
      ops.forEach(op => {
        const n = makeNode(op.name || '(op)', 'op', { kind: 'operation', data: op });
        // Input branch
        const inLabel = 'input' + (op.input && op.input.message ? (': ' + op.input.message) : '');
        const ni = makeNode(inLabel, null, { kind: 'operationIO', data: Object.assign({ dir: 'input' }, op.input || {}) });
        if (op.input) {
          if (op.input.element) ni.children.appendChild(makeNode('element: ' + op.input.element, null, { kind: 'element', data: { name: op.input.element }}).li);
          if (op.input.type)    ni.children.appendChild(makeNode('type: ' + op.input.type, null, { kind: 'complexType', data: { name: op.input.type, fields: op.input.fields || [] }}).li);
          (op.input.fields || []).forEach(f => {
            const req = isRequired(f) ? 'req' : null;
            const fn = makeNode(fieldLabel(f), req, { kind: 'field', data: f });
            ni.children.appendChild(fn.li);
          });
        }
        n.children.appendChild(ni.li);
        // Output branch
        const outLabel = 'output' + (op.output && op.output.message ? (': ' + op.output.message) : '');
        const no = makeNode(outLabel, null, { kind: 'operationIO', data: Object.assign({ dir: 'output' }, op.output || {}) });
        if (op.output) {
          if (op.output.element) no.children.appendChild(makeNode('element: ' + op.output.element, null, { kind: 'element', data: { name: op.output.element }}).li);
          if (op.output.type)    no.children.appendChild(makeNode('type: ' + op.output.type, null, { kind: 'complexType', data: { name: op.output.type, fields: op.output.fields || [] }}).li);
          (op.output.fields || []).forEach(f => {
            const req = isRequired(f) ? 'req' : null;
            const fn = makeNode(fieldLabel(f), req, { kind: 'field', data: f });
            no.children.appendChild(fn.li);
          });
        }
        n.children.appendChild(no.li);
        root0.children.appendChild(n.li);
      });

      const root1 = makeNode('Elements', String(data.elements.length));
      data.elements.forEach(el => {
        const bEl = isRequired(el) ? 'req' : null;
        const n = makeNode(el.name + (el.type ? (': ' + el.type) : ''), bEl, { kind: 'element', data: el });
        if (el.children && el.children.length) {
          el.children.forEach(ch => {
            const b = isRequired(ch) ? 'req' : null;
            const cn = makeNode(fieldLabel(ch), b, { kind: 'field', data: ch });
            n.children.appendChild(cn.li);
          });
        }
        root1.children.appendChild(n.li);
      });

      const root2 = makeNode('Complex Types', String(data.complexTypes.length));
      data.complexTypes.forEach(ct => {
        const suffix = ct.extends ? (' extends ' + ct.extends) : '';
        const n = makeNode((ct.name || '(anon)') + suffix, (ct.fields || []).length + ' fields', { kind: 'complexType', data: ct });
        (ct.fields || []).forEach(f => {
          const b = isRequired(f) ? 'req' : null;
          const fn = makeNode(fieldLabel(f), b, { kind: 'field', data: f });
          if (f.children && f.children.length) {
            f.children.forEach(g => {
              const br = isRequired(g) ? 'req' : null;
              const gn = makeNode(fieldLabel(g), br, { kind: 'field', data: g });
              fn.children.appendChild(gn.li);
            });
          }
          n.children.appendChild(fn.li);
        });
        root2.children.appendChild(n.li);
      });

      const root3 = makeNode('Simple Types', String(data.simpleTypes.length));
      data.simpleTypes.forEach(st => {
        const base = st.base ? (' : ' + st.base) : '';
        const n = makeNode((st.name || '(anon)') + base, (st.enumeration || []).length ? 'enum' : null, { kind: 'simpleType', data: st });
        if (st.enumeration && st.enumeration.length) {
          st.enumeration.forEach(v => {
            const vn = makeNode(String(v), null, { kind: 'enum', data: { value: v, of: st.name || '' } });
            n.children.appendChild(vn.li);
          });
        }
        root3.children.appendChild(n.li);
      });

      treeEl.appendChild(root0.li);
      treeEl.appendChild(root1.li);
      treeEl.appendChild(root2.li);
      treeEl.appendChild(root3.li);

      // auto-expand top-level
      [root0, root1, root2, root3].forEach(r => { r.node.click(); });

      // Default details: summary until a node is selected
      renderSummary(data);
    }

    function escapeHtml(s) {
      return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    }

    function renderSummary(data) {
      clear(detailsEl);
      const tbl = document.createElement('table');
      tbl.innerHTML = `
        <thead>
          <tr>
            <th>Property</th>
            <th>Value</th>
          </tr>
        </thead>
        <tbody>
          <tr><td class="mono">wsdl</td><td class="mono">${escapeHtml(data.summary.wsdl || '')}</td></tr>
          <tr><td class="mono">targetNamespace</td><td class="mono">${escapeHtml(data.summary.targetNamespace || '')}</td></tr>
          <tr><td class="mono">schemaCount</td><td class="mono">${String(data.summary.schemaCount || 0)}</td></tr>
          <tr><td class="mono">elements</td><td>${String(data.summary.counts.elements || 0)}</td></tr>
          <tr><td class="mono">complexTypes</td><td>${String(data.summary.counts.complexTypes || 0)}</td></tr>
          <tr><td class="mono">simpleTypes</td><td>${String(data.summary.counts.simpleTypes || 0)}</td></tr>
          <tr><td class="mono">operations</td><td>${String((data.summary.counts && data.summary.counts.operations) || (data.operations ? data.operations.length : 0) || 0)}</td></tr>
        </tbody>
      `;
      detailsEl.appendChild(tbl);
    }

    function kvRow(k, v, monoVal = true) {
      return `<tr><td class="mono">${escapeHtml(k)}</td><td${monoVal ? ' class="mono"' : ''}>${escapeHtml(v)}</td></tr>`;
    }

    function renderDetails(payload) {
      clear(detailsEl);
      const { kind, data } = payload || {};
      const tbl = document.createElement('table');
      let html = `<thead><tr><th>Property</th><th>Value</th></tr></thead><tbody>`;
      if (kind === 'element') {
        html += kvRow('kind', 'element', false);
        html += kvRow('name', data.name || '');
        if (data.type) html += kvRow('type', data.type);
        if (data.inlineType) html += kvRow('inlineType', data.inlineType);
        if (data.ref) html += kvRow('ref', data.ref);
        if (data.minOccurs) html += kvRow('minOccurs', String(data.minOccurs));
        if (data.maxOccurs) html += kvRow('maxOccurs', String(data.maxOccurs));
        if (data.nillable) html += kvRow('nillable', String(data.nillable));
        html += kvRow('children', String((data.children || []).length));
      } else if (kind === 'operation') {
        html += kvRow('kind', 'operation', false);
        html += kvRow('name', data.name || '');
        if (data.input) {
          html += kvRow('input.message', data.input.message || '');
          if (data.input.element) html += kvRow('input.element', data.input.element);
          if (data.input.type) html += kvRow('input.type', data.input.type);
          html += kvRow('input.fields', String((data.input.fields || []).length));
        }
        if (data.output) {
          html += kvRow('output.message', data.output.message || '');
          if (data.output.element) html += kvRow('output.element', data.output.element);
          if (data.output.type) html += kvRow('output.type', data.output.type);
          html += kvRow('output.fields', String((data.output.fields || []).length));
        }
      } else if (kind === 'operationIO') {
        html += kvRow('kind', data.dir === 'input' ? 'operation input' : 'operation output', false);
        if (data.message) html += kvRow('message', data.message);
        if (data.element) html += kvRow('element', data.element);
        if (data.type) html += kvRow('type', data.type);
        html += kvRow('fields', String((data.fields || []).length));
      } else if (kind === 'field') {
        html += kvRow('kind', 'field', false);
        html += kvRow('name', data.name || '');
        if (data.type) html += kvRow('type', data.type);
        if (data.inlineType) html += kvRow('inlineType', data.inlineType);
        if (data.minOccurs) html += kvRow('minOccurs', String(data.minOccurs));
        if (data.maxOccurs) html += kvRow('maxOccurs', String(data.maxOccurs));
        if (data.nillable) html += kvRow('nillable', String(data.nillable));
        html += kvRow('children', String((data.children || []).length));
      } else if (kind === 'complexType') {
        html += kvRow('kind', 'complexType', false);
        html += kvRow('name', data.name || '');
        if (data.extends) html += kvRow('extends', data.extends);
        html += kvRow('fields', String((data.fields || []).length));
      } else if (kind === 'simpleType') {
        html += kvRow('kind', 'simpleType', false);
        html += kvRow('name', data.name || '');
        if (data.base) html += kvRow('base', data.base);
        if (data.enumeration && data.enumeration.length) {
          html += `<tr><td class="mono">enum</td><td class="mono">${data.enumeration.map(v => escapeHtml(String(v))).join(', ')}</td></tr>`;
        }
      } else if (kind === 'enum') {
        html += kvRow('kind', 'enum value', false);
        html += kvRow('of', data.of || '');
        html += kvRow('value', String(data.value));
      } else {
        html += kvRow('kind', kind || 'unknown', false);
      }
      html += `</tbody>`;
      tbl.innerHTML = html;
      detailsEl.appendChild(tbl);
    }
    function loadWSDL(wsdl) {
      fetchJson(wsdl)
        .then(data => {
          renderTree(data);
        })
        .catch(err => {
          clear(treeEl);
          clear(detailsEl);
          treeEl.innerHTML = `<li class="mono">Error: ${escapeHtml(err.message || String(err))}</li>`;
        });
    }

    // Initial load
    const startWSDL = qs.get('wsdl') || input.value;
    if (startWSDL) {
      loadWSDL(startWSDL);
    }
  </script>
</body>
</html>
