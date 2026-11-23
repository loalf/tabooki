<?php

declare(strict_types=1);

namespace App\Food\Bedca;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads BEDCA (the Spanish food composition database, AESAN) through the XML query interface its
 * website uses: there is no download or official API. Its conditions of use (docs/INGREDIENT-
 * KNOWLEDGE.md, "Data licences") allow personal, non-commercial use; so it is asked politely, one
 * food a second, and every answer is kept under var/bedca (never in git) and asked only once.
 */
final readonly class BedcaClient
{
    private const string URL = 'https://www.bedca.net/bdpub/procquery.php';
    private const int PAUSE_MICROSECONDS = 1_000_000;

    public function __construct(
        #[Autowire(service: 'bedca.http_client')]
        private HttpClientInterface $httpClient,
        #[Autowire('%app.bedca_dir%')]
        private string $cacheDir,
    ) {
    }

    /** @return list<BedcaFood> every food, by Spanish name */
    public function foods(): array
    {
        $xml = $this->query('foods', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <foodquery><type level="1"/><selection><atribute name="f_id"/><atribute name="f_ori_name"/><atribute name="f_eng_name"/></selection>
            <condition><cond1><atribute1 name="f_origen"/></cond1><relation type="LIKE"/><cond3>BEDCA</cond3></condition>
            <order ordtype="ASC"><atribute3 name="f_ori_name"/></order></foodquery>
            XML);
        $foods = [];
        foreach ($xml->food as $food) {
            $id = (int) $food->f_id;
            $es = trim((string) $food->f_ori_name);
            if ($id > 0 && '' !== $es) {
                $foods[] = new BedcaFood($id, $es, trim((string) $food->f_eng_name));
            }
        }

        return $foods;
    }

    /**
     * Its values per 100 g of edible portion, by EuroFIR component code ("ENERC", "PROT", "NA"…):
     * the value (null when given as a trace or not given) and its unit.
     *
     * @return array<string, array{value: float|null, unit: string}>
     */
    public function components(int $id): array
    {
        $xml = $this->query('food-'.$id, <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <foodquery><type level="2"/><selection><atribute name="f_id"/><atribute name="c_id"/><atribute name="eur_name"/><atribute name="best_location"/><atribute name="v_unit"/><atribute name="mu_descripcion"/></selection>
            <condition><cond1><atribute1 name="f_id"/></cond1><relation type="EQUAL"/><cond3>{$id}</cond3></condition>
            <order ordtype="ASC"><atribute3 name="componentgroup_id"/></order></foodquery>
            XML);
        $components = [];
        foreach ($xml->food->foodvalue ?? [] as $value) {
            $code = trim((string) $value->eur_name);
            $amount = trim((string) $value->best_location);
            if ('' !== $code) {
                $components[$code] = ['value' => is_numeric($amount) ? (float) $amount : null, 'unit' => trim((string) $value->v_unit)];
            }
        }

        return $components;
    }

    private function query(string $name, string $body): \SimpleXMLElement
    {
        $file = $this->cacheDir.'/'.$name.'.xml';
        if (is_file($file)) {
            $text = (string) file_get_contents($file);
        } else {
            usleep(self::PAUSE_MICROSECONDS);
            $text = $this->httpClient->request('POST', self::URL, [
                'headers' => ['Content-Type' => 'text/xml'],
                'body' => $body,
                'timeout' => 60,
            ])->getContent();
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0775, true);
            }
            file_put_contents($file, $text);
        }
        $xml = @simplexml_load_string($text);
        if (false === $xml) {
            @unlink($file);
            throw new \RuntimeException('BEDCA answered something that isn\'t XML.');
        }

        return $xml;
    }
}
