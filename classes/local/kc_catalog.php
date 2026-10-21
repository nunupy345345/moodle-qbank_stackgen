<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * 前提知識（KC: Knowledge Component）の分類表。
 *
 * プラグイン同梱の `data/kc_master.csv`（伊藤さんが整理した知識要素の一覧。元データは
 * 開発リポジトリの `fixtures/kc/kc_master.csv`）を読み込む。約40件の静的な分類データで
 * 埋め込み検索は不要なため、DBテーブルは持たずCSVを直接ソースにする。
 */
class kc_catalog {

    private const CSV_PATH = __DIR__ . '/../../data/kc_master.csv';

    /** @var array|null [['id'=>'D05', 'ja'=>'合成関数の微分', 'domain'=>'微分'], ...] */
    private static ?array $cache = null;

    /**
     * トップレベルKC一覧を返す（kc_subtypes.csvの細目・前提知識チェーンは含まない）。
     *
     * @return array
     */
    public static function get_all(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = [];
        if (is_readable(self::CSV_PATH) && ($fh = fopen(self::CSV_PATH, 'r')) !== false) {
            fgetcsv($fh); // ヘッダ行を読み捨てる。
            while (($row = fgetcsv($fh)) !== false) {
                $id = trim((string)($row[0] ?? ''));
                if ($id === '') {
                    continue;
                }
                $rows[] = [
                    'id'     => $id,
                    'ja'     => trim((string)($row[1] ?? '')),
                    'domain' => trim((string)($row[3] ?? '')),
                ];
            }
            fclose($fh);
        }

        return self::$cache = $rows;
    }

    /**
     * KC IDから日本語名を引く。見つからなければ空文字列。
     */
    public static function label(string $id): string {
        foreach (self::get_all() as $kc) {
            if ($kc['id'] === $id) {
                return $kc['ja'];
            }
        }
        return '';
    }
}
