<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Extracts question/answer pairs and glossary terms from the `faq` and
 * `glossary` translation catalogs, so their JSON-LD stays in sync with the
 * rendered pages (which are built from the same catalogs).
 */
final readonly class CatalogStructuredContent
{
    public function __construct(
        #[Autowire(service: 'translator')]
        private TranslatorBagInterface $translator,
    ) {
    }

    /**
     * Every `<prefix>.question` key becomes a question; its answer is the
     * sibling texts under the same prefix, in catalog order.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqEntries(?string $locale = null): array
    {
        $entries = [];
        foreach ($this->groupByPrefix('faq', $locale) as $fields) {
            if (!isset($fields['question'])) {
                continue;
            }
            $question = $fields['question'];
            unset($fields['question']);
            if ([] === $fields) {
                continue;
            }
            $entries[] = ['question' => $question, 'answer' => implode(' ', $fields)];
        }

        return $entries;
    }

    /**
     * Every `<prefix>.name` key becomes a term; its description is the
     * sibling `description*` texts under the same prefix.
     *
     * @return list<array{name: string, description: string}>
     */
    public function glossaryTerms(?string $locale = null): array
    {
        $terms = [];
        foreach ($this->groupByPrefix('glossary', $locale) as $fields) {
            if (!isset($fields['name'])) {
                continue;
            }
            $descriptions = array_filter(
                $fields,
                static fn (string $key): bool => str_starts_with($key, 'description'),
                \ARRAY_FILTER_USE_KEY,
            );
            if ([] === $descriptions) {
                continue;
            }
            $terms[] = ['name' => $fields['name'], 'description' => implode(' ', $descriptions)];
        }

        return $terms;
    }

    /**
     * @return array<string, array<string, string>> prefix => [leaf key => text]
     */
    private function groupByPrefix(string $domain, ?string $locale): array
    {
        $groups = [];
        foreach ($this->translator->getCatalogue($locale)->all($domain) as $key => $text) {
            $pos = strrpos((string) $key, '.');
            if (false === $pos) {
                continue;
            }
            $groups[substr((string) $key, 0, $pos)][substr((string) $key, $pos + 1)] = (string) $text;
        }

        return $groups;
    }
}
