<?php

namespace App\Model;

use App\Entity\Rfc;
use App\Repository\RfcRepository;
use Symfony\Component\CssSelector\CssSelectorConverter;

class Synchronization
{
    private $rfcRepository;
    private $rfcFetcher;

    public function __construct(RfcRepository $rfcRepository, RfcDomFetcher $rfcFetcher)
    {
        $this->rfcRepository = $rfcRepository;
        $this->rfcFetcher = $rfcFetcher;
    }

    public function getRfcUrlsInVoting()
    {
        $converter  = new CssSelectorConverter;
        $document = $this->rfcFetcher->getRfcDom('https://wiki.php.net/rfc');
        $xPath      = new \DOMXPath($document);
        $rfcs       = [];

        foreach ($xPath->query($converter->toXPath('#in_voting_phase + .level2 .li')) as $listing) {
            /** @var \DOMNode $listing */
            /** @var \DOMElement $link */
            $link   = $xPath->query($converter->toXPath('a'), $listing)->item(0);
            $rfcs[] = $link->getAttribute('href');
        }

        return array_map(function ($link) {
            return 'https://wiki.php.net' . $link;
        }, $rfcs);
    }

    public function synchronizeRfcs(array $rfcUrls)
    {
        $rfcs = [];

        foreach ($rfcUrls as $rfcUrl) {
            $rfcs[] = $this->synchronizeRfc($rfcUrl);
        }

        $this->rfcRepository->flush();

        return $rfcs;
    }

    private function synchronizeRfc(string $rfcUrl) : Rfc
    {
        $matches = [];
        $rfc = $this->rfcRepository->findOneByUrl($rfcUrl);

        if (!$rfc) {
            $rfc = new Rfc();
            $rfc->url = $rfcUrl;
        }

        $dom = $this->rfcFetcher->getRfcDom($rfcUrl);
        $content = $dom->saveHTML();
        $xpath = new \DOMXpath($dom);

        $rfc->title = trim(str_replace('PHP RFC:', '', $xpath->evaluate('string(//h1)')));

        if (strlen($rfc->targetPhpVersion) === 0) {
            $targetVersionRegexps = [
                '(Targets: ([^<]+))',
                '(Proposed version: ([^<]+))',
                '(Proposed Version: ([^<]+))',
                '(Target version: ([^<]+))',
                '(Target Version: ([^<]+))',
            ];
            foreach ($targetVersionRegexps as $targetVersionRegexp) {
                if (preg_match($targetVersionRegexp, $content, $matches)) {
                    $rfc->targetPhpVersion = trim(str_replace('PHP', '', $matches[1]));
                }
            }
        }

        $nodes = $xpath->evaluate('//form[@name="doodle__form"]');

        $first = true;
        foreach ($nodes as $form) {
            $rows = $xpath->evaluate('table[@class="inline"]/tbody/tr', $form);
            $voteId = $form->getAttribute('id');

            $vote = $rfc->getVoteById($voteId);

            $vote->primaryVote = $first;
            $first = false;

            $options = array();
            foreach ($rows as $row) {
                switch ((string)$row->getAttribute('class')) {
                    case 'row0':
                        $vote->question = trim($xpath->evaluate('string(th/text())', $row));
                        // do nothing;
                        break;

                    case 'row1':
                        foreach ($xpath->evaluate('td', $row) as $optionNode) {
                            $option = trim($optionNode->nodeValue);
                            if ($option !== "Real name") {
                                $options[] = $option;
                                $vote->currentVotes[$option] = 0;
                            }
                        }
                        break;

                    default:
                        $firstColumn = trim($xpath->evaluate('string(td[1])', $row));

                        if ($firstColumn === 'This poll has been closed.') {
                            $rfc->status = Rfc::CLOSE;
                            break;
                        }

                        if (!preg_match('(\(([^\)]+)\))', $firstColumn, $matches)) {
                            break;
                        }

                        $option = -1;
                        foreach ($xpath->evaluate('td', $row) as $optionNode) {
                            if ($optionNode->getAttribute('style') == 'background-color:#AFA') {
                                $imgTitle = $xpath->evaluate('img[@title]', $optionNode);
                                if ($imgTitle && $imgTitle->length > 0) {
                                    $time = \DateTime::createFromFormat('Y/m/d H:i', $imgTitle->item(0)->getAttribute('title'), new \DateTimeZone('UTC'));
                                    $time->modify('-60 minute'); // hardcode how far both servers are away from each other timezone-wise

                                    if ($rfc->firstVote > $time) {
                                        $rfc->firstVote = $time;
                                    }
                                }
                                break;
                            }
                            $option++;
                        }
                        $vote->currentVotes[$options[$option]]++;
                        break;
                }
            }

            $rfc->rejected = false;

            if ($rfc->status == Rfc::CLOSE && $vote->primaryVote && isset($vote->currentVotes['Yes'])) {
                $yesShare = $vote->currentVotes['Yes'] / array_sum($vote->currentVotes);
                if ($yesShare < ($vote->passThreshold / 100)) {
                    $rfc->rejected = true;
                }
            }
        }

        $this->rfcRepository->persist($rfc);

        return $rfc;
    }
}