<?php

namespace App\Http\Controllers\Api;

use App\Book;
use App\Edition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class EditionController extends ApiController
{
    public function index(Request $request)
    {
        $type = $request->input('type');
        $letter = $request->input('letter');
        $page = $request->input('page');

        $query = $type . "," . $letter;

        $key = md5('editions' . $page . $type . $letter);

        if (Cache::tags(['editions'])->has($key)) {
            $cacheResp = Cache::tags(['editions'])->get($key);
            return $this->apiResp(200, $query, unserialize($cacheResp));
        }

        $allEditions = Edition::where('is_public', 1)
            ->whereHas('singleBook')
            ->where(function ($q) use ($type) {
                $type ? $q->where('type', $type) : null;
            })
            ->get();

        $sortedEditions = Edition::where('is_public', 1)
            ->whereHas('singleBook')
            ->where(function ($q) use ($letter, $type) {
                $letter ? $q->where('name', 'LIKE', $letter . "%") : null;
                $type ? $q->where('type', $type) : null;
            })
            ->orderBy('name', 'asc')
            ->paginate(32);

        $resp["type"] = $type ? $type : null;
        $resp["letter"] = $letter ? $letter : null;

        $resp["rus_letters"] = $this->getAlphas($allEditions, 'rus');
        $resp["eng_letters"] = $this->getAlphas($allEditions, 'eng');

        $resp["editions"] = $sortedEditions->map(function($edition) {
            return [
                "name" => $edition->name,
                "url" => "/editions/" . $edition->id,
                "image" => "/storage/book_pages/" . $edition->singleBook["id"] . "/" . $edition->singleBook["cover"]
            ];
        });
        $resp["current_page"] = $sortedEditions->currentPage();
        $resp["per_page"] = $sortedEditions->perPage();
        $resp["total_items"] = $sortedEditions->total();
        $resp["total_pages"] = ceil($sortedEditions->total() / $sortedEditions->perPage());

        Cache::tags(['editions'])->put($key, serialize($resp), self::CACHE_TIME);

        return $this->apiResp(200, $query, $resp);
    }

    public function edition($editionId)
    {
        $key = md5('edition' . $editionId);

        if (Cache::tags(['editions'])->has($key)) {
            $cacheResp = Cache::tags(['editions'])->get($key);
            return $this->apiResp(200, null, unserialize($cacheResp));
        }

        $editionItems = Edition::where('id', $editionId)
            ->with('books')
            ->firstOrFail();

        $itemsByYear = $editionItems->books->groupBy(
            function($item) {
                return Carbon::createFromFormat('d.m.Y', $item->book_year)->year;
            }
        )->map(function($item){
            return $item->groupBy(function($item){
                return Carbon::createFromFormat('d.m.Y', $item->book_year)->month;
            });
        });

        $monthNames = Edition::getMonths();

        $resp["data"] = [
                "type" => $editionItems->type,
                "name" => $editionItems->name,
                "description" => $editionItems->description ?: null,
                "url" => '/editions/'. $editionItems->id
            ];

        foreach ($itemsByYear as $year => $months) {

            $resp["data"]["years"][$year] = [
                "value" => $year,
                "url" => '/edition/'. $editionId . '/' . $year
            ];

            $yearCount = 0;

            foreach ($monthNames as $month => $name) {

                $monthCount = isset($months[$month]) ? $months[$month]->count() : 0;

                $resp["data"]["years"][$year]["months"][] = [
                    "value" => $name,
                    "url" => $monthCount ? '/edition/' . $editionId . '/' . $year . '/' . $month : null,
                    "count" => $monthCount
                ];

                $yearCount += $monthCount;
            }

            $resp["data"]["years"][$year]["count"] = $yearCount;
        }

        Cache::tags(['editions'])->put($key, serialize($resp), self::CACHE_TIME);

        return $this->apiResp(200, null, $resp);
    }

    public function one(Edition $edition, $year, $month = null)
    {
        $key = md5('edition' . $edition->id . $year . $month);

        if (Cache::tags(['editions'])->has($key)) {
            $cacheResp = Cache::tags(['editions'])->get($key);
            return $this->apiResp(200, null, unserialize($cacheResp));
        }

        $allEditionItems = Book::select('book_year')
            ->where('is_active', 1)
            ->where('edition_id', $edition->id)
            ->whereYear('book_year', $year)
            ->get();

        $editionItems = $edition->with(['books' => function ($q) use ($year, $month) {
            $q->where('is_active', 1);
            $month ?
                $q->whereYear('book_year', $year)
                    ->whereMonth('book_year', $month)
                    ->orderBy('book_year', 'asc')
                :
                $q->whereYear('book_year', $year)
                    ->orderBy('book_year', 'asc');
            }])
            ->findOrFail($edition->id);

        $itemsByMonth = $allEditionItems->groupBy(
            function($item) {
                return Carbon::createFromFormat('d.m.Y', $item->book_year)->month;
            });

        $monthNames = Edition::getMiniMonths();

        $resp["data"] = [
            "type" => $edition->type,
            "name" => $edition->name,
            "year" => $year,
            "month" => $month,
            "url" => "/edition/" . $edition->id . "/" . $year
        ];

        foreach ($monthNames as $month => $books) {

            $monthCount = isset($itemsByMonth[$month]) ? $itemsByMonth[$month]->count() : 0;

            $resp["data"]["months"][] = [
                "value" => $monthNames[$month],
                "url" => $monthCount ? '/edition/' . $edition->id . '/' . $year . '/' . $month : null,
                "count" => $monthCount
            ];
        }

        $resp["data"]["books"] = $editionItems->books->map(function ($editionItem) {
            return [
                "name" => $editionItem->book_name,
                "image" => "/storage/book_pages/" . $editionItem->id . "/" . $editionItem->cover,
                "url" => "/book/" . $editionItem->id
            ];
        });

        Cache::tags(['editions'])->put($key, serialize($resp), self::CACHE_TIME);

        return $this->apiResp(200, null, $resp);
    }

    private static function getAlphas($collection, $lang)
    {
        $editionSymbols = [];
        foreach ($collection as $item) {
            $editionSymbols[] = mb_substr($item->name, 0, 1, 'utf-8');
        }
        $editionSymbols = array_flip($editionSymbols);

        $resp = [];
        switch ($lang) {
            case ('rus'):
                $rusAlphas = [];
                foreach (range(chr(0xC0),chr(0xDF)) as $alpha) {
                    $rusAlphas[] = iconv('CP1251','UTF-8',$alpha);
                }
                $rusAlphas = array_flip($rusAlphas);

                foreach ($rusAlphas as $k => $v) {
                    $resp[] = [
                        'letter' => $k,
                        'value' => array_key_exists($k, $editionSymbols) ? "true" : null
                    ];
                }
                return $resp;
                break;
            case('eng'):
                $engAlphas = [];
                foreach (range('A', 'Z') as $alpha) {
                    $engAlphas[] = $alpha;
                }
                $engAlphas = array_flip($engAlphas);

                foreach ($engAlphas as $k => $v) {
                    $resp[] = [
                        'letter' => $k,
                        'value' => array_key_exists($k, $editionSymbols) ? "true" : null
                    ];
                }
                return $resp;
                break;
        }
    }
}
