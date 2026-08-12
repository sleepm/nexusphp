{{-- Nexus-style pagination links, mirrors the legacy pager() markup (.nexus-pagination). --}}
@php
    $langFunctions = get_legacy_lang_file('functions');
    $prevText = $langFunctions['text_prev'] ?? 'Prev';
    $nextText = $langFunctions['text_next'] ?? 'Next';
    $count = $paginator->total();
    $rpp = $paginator->perPage();
    $pages = (int) ceil($count / $rpp);
    $page = max(0, $paginator->currentPage() - 1);
    $mp = $pages - 1;

    $query = request()->query();
    unset($query['page']);
    $href = $paginator->path() . (empty($query) ? '?' : '?' . http_build_query($query) . '&');

    $as = '<b title="">&lt;&lt;&nbsp;' . htmlspecialchars($prevText) . '</b>';
    if ($page >= 1) {
        $pagerLinks = '<a href="' . htmlspecialchars($href . 'page=' . ($page - 1)) . '">' . $as . '</a>';
    } else {
        $pagerLinks = '<font class="gray">' . $as . '</font>';
    }
    $pagerLinks .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    $as = '<b title="">' . htmlspecialchars($nextText) . '&nbsp;&gt;&gt;</b>';
    if ($page < $mp && $mp >= 0) {
        $pagerLinks .= '<a href="' . htmlspecialchars($href . 'page=' . ($page + 1)) . '">' . $as . '</a>';
    } else {
        $pagerLinks .= '<font class="gray">' . $as . '</font>';
    }

    $pagerRanges = [];
    if ($count) {
        $dotted = 0;
        $dotspace = 3;
        $dotend = $pages - $dotspace;
        $curdotend = $page - $dotspace;
        $curdotstart = $page + $dotspace;
        for ($i = 0; $i < $pages; $i++) {
            if (($i >= $dotspace && $i <= $curdotend) || ($i >= $curdotstart && $i < $dotend)) {
                if (!$dotted) {
                    $pagerRanges[] = '...';
                }
                $dotted = 1;
                continue;
            }
            $dotted = 0;
            $start = $i * $rpp + 1;
            $end = min($start + $rpp - 1, $count);
            $text = $start . '&nbsp;-&nbsp;' . $end;
            if ($i != $page) {
                $pagerRanges[] = '<a href="' . htmlspecialchars($href . 'page=' . $i) . '"><b>' . $text . '</b></a>';
            } else {
                $pagerRanges[] = '<font class="gray"><b>' . $text . '</b></font>';
            }
        }
        $pagerString = implode(' | ', $pagerRanges);
        $paginationTop = "<p align=\"center\" class='nexus-pagination'>$pagerLinks<br />$pagerString</p>";
        $paginationBottom = "<p align=\"center\" class='nexus-pagination'>$pagerString<br />$pagerLinks</p>";
    } else {
        $paginationTop = $paginationBottom = "<p align=\"center\" class='nexus-pagination'>$pagerLinks</p>";
    }
@endphp
@if (($position ?? 'bottom') == 'top')
    {!! $paginationTop !!}
@else
    {!! $paginationBottom !!}
@endif