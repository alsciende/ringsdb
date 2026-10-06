<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Card;
use App\Entity\CardPrinting;
use App\Entity\Pack;
use App\Entity\Sphere;
use App\Entity\Type;
use App\Repository\CardPrintingRepository;
use App\Repository\CardRepository;
use App\Repository\CycleRepository;
use App\Repository\PackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CSVController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @Route("/admin/csv/upload", name="csv_upload_form", methods={"GET"})
     */
    public function uploadFormAction(): Response
    {
        return $this->render('CSV/upload_form.html.twig');
    }

    /**
     * @Route("/admin/csv/upload", name="csv_upload_process", methods={"POST"})
     */
    public function uploadProcessAction(Request $request, CardRepository $cardRepository, CardPrintingRepository $cardPrintingRepository, CycleRepository $cycleRepository, PackRepository $packRepository): Response
    {
        $inputCode = (string) $request->request->get('code');
        $inputOldCode = (string) $request->request->get('old_code');
        $inputName = (string) $request->request->get('name');
        $inputFileName = $request->files->get('upfile')->getPathname();
        $content = str_replace('﻿', '', trim((string) file_get_contents($inputFileName)));
        $content = str_replace("\r", "\n", str_replace("\n", '<br/>', str_replace("\r\n", "\r", $content)));

        $content_array = explode("\n", $content);
        if (count($content_array) < 2) {
            return new Response('No cards found in the CSV file');
        }

        $columns = str_getcsv(array_shift($content_array));
        $cards = [];
        $newIds = [];
        foreach ($content_array as $row) {
            $card = [];
            $row = str_getcsv($row);
            for ($i = 0; $i < count($row); ++$i) {
                $card[$columns[$i]] = str_replace('<br/>', "\n", (string) $row[$i]);
            }

            $newIds[$card['octgnid']] = 1;
            $cards[] = $card;
        }

        $packRepo = $packRepository;
        $pack = $packRepo->findOneBy(['code' => $inputCode]);
        $oldPack = $packRepo->findOneBy(['code' => $inputOldCode]);
        if (!$pack && !$oldPack) {
            $cycleRepo = $cycleRepository;
            // 'ALeP' cycle code doesn't exist; fall back to the most recent cycle.
            $cycle = $cycleRepo->findOneBy(['code' => 'ALeP']) ?? $cycleRepo->findOneBy([], ['id' => 'DESC']);
            if (!$cycle) {
                return new Response('Error: no cycle found to assign to new pack');
            }

            $pack = new Pack();
            $pack->setCode($inputCode);
            $pack->setName($inputName);
            $pack->setPosition(1);
            $pack->setSize(1);
            $pack->setDateRelease(new \DateTime('2030-02-01'));
            $pack->setCycle($cycle);
            $this->entityManager->persist($pack);
            $this->entityManager->flush();
        } elseif (!$pack) {
            $pack = $oldPack;
            $pack->setCode($inputCode);
            $this->entityManager->persist($pack);
            $this->entityManager->flush();
        }

        if ($pack->getName() != $inputName) {
            $pack->setName($inputName);
            $this->entityManager->persist($pack);
            $this->entityManager->flush();
        }

        // Build oldIds from the pack's CardPrintings (not Card rows, since a
        // canonical Card may appear in multiple packs after the refactor).
        $oldIds = [];
        foreach ($pack->getPrintings() as $printing) {
            $oldIds[$printing->getOctgnid()] = 1;
            if ($printing->getCard()
                && !array_key_exists((string) $printing->getOctgnid(), $newIds)
                && !str_contains($printing->getCard()->getName() ?? '', '[deleted]')) {
                $card = $printing->getCard();
                $card->setName('[deleted] '.$card->getName());
                $card->setCode($card->getCode().'_'.uniqid());
            }
        }

        // Cards that existed in ALePMotKA and are now being "promoted" into a
        // main pack upload should have the MotKA printing's Card marked deleted.
        $motkPack = $packRepo->findOneBy(['code' => 'ALePMotKA']);
        if ($motkPack) {
            foreach ($motkPack->getPrintings() as $printing) {
                if ($printing->getCard()
                    && array_key_exists((string) $printing->getOctgnid(), $oldIds)
                    && !str_contains($printing->getCard()->getName() ?? '', '[deleted]')) {
                    $card = $printing->getCard();
                    $card->setName('[deleted] '.$card->getName());
                    $card->setCode($card->getCode().'_'.uniqid());
                }
            }
        }

        $printingRepo = $cardPrintingRepository;
        $cardMeta = $this->entityManager->getClassMetadata(Card::class);
        $cardFieldNames = $cardMeta->getFieldNames();
        $cardAssocMappings = $cardMeta->getAssociationMappings();
        $printingMeta = $this->entityManager->getClassMetadata(CardPrinting::class);
        $printingFieldNames = $printingMeta->getFieldNames();
        foreach ($cards as $card) {
            $changed = false;
            // Determine the target pack for this card: use the CSV 'pack'
            // column if it names a different pack, otherwise default to the
            // primary upload pack.
            $cardPack = $pack;
            if (!empty($card['pack']) && $card['pack'] !== $pack->getName()) {
                $namedPack = $packRepo->findOneBy(['name' => $card['pack']]);
                if ($namedPack) {
                    $cardPack = $namedPack;
                }
            }

            // Look up by octgnid scoped to the card's target pack.
            $printingEntity = $printingRepo->findOneBy(['octgnid' => $card['octgnid'], 'pack' => $cardPack]);
            if ($printingEntity) {
                $cardEntity = $printingEntity->getCard();
            } else {
                // For cards being promoted from MotKA into a non-MotKA pack,
                // reuse the existing canonical Card rather than creating a duplicate.
                $cardEntity = null;
                if ($motkPack && $cardPack !== $motkPack) {
                    $motkPrinting = $printingRepo->findOneBy(['octgnid' => $card['octgnid'], 'pack' => $motkPack]);
                    if ($motkPrinting) {
                        $cardEntity = $motkPrinting->getCard();
                    }
                }

                if (!$cardEntity) {
                    $cardRepo = $cardRepository;
                    $cardEntity = $cardRepo->findOneBy(['code' => $card['code']]);
                }

                if (!$cardEntity) {
                    $cardEntity = new Card();
                    $now = new \DateTime();
                    $cardEntity->setDateCreation($now);
                    $cardEntity->setDateUpdate($now);
                    $this->entityManager->persist($cardEntity);
                }

                $printingEntity = new CardPrinting();
                $now = new \DateTime();
                $printingEntity->setDateCreation($now);
                $printingEntity->setDateUpdate($now);
                $printingEntity->setPack($cardPack);
                $printingEntity->setCard($cardEntity);
                $printingEntity->setOctgnid($card['octgnid']);
                $printingEntity->setPosition(1);
                $printingEntity->setQuantity(1);
                // imageCode is non-nullable; default to the card code and let
                // the field loop below override it from the CSV column.
                $printingEntity->setImageCode($card['imageCode'] ?? $card['image_code'] ?? $card['code'] ?? '');
                $this->entityManager->persist($printingEntity);
                $changed = true;
            }

            foreach ($card as $colName => $value) {
                // octgnid is set on the printing at creation; pack comes from the form.
                if ('octgnid' === $colName || 'pack' === $colName) {
                    continue;
                }

                $getter = str_replace(' ', '', ucwords(str_replace('_', ' ', "get_{$colName}")));
                $setter = str_replace(' ', '', ucwords(str_replace('_', ' ', "set_{$colName}")));
                if (array_key_exists($colName, $cardAssocMappings)) {
                    // Association field on Card (type, sphere).
                    $associationMapping = $cardAssocMappings[$colName];
                    /** @var class-string<Type|Sphere> $targetEntity */
                    $targetEntity = $associationMapping['targetEntity'];
                    $associationRepository = $this->entityManager->getRepository($targetEntity);
                    /** @var Type|Sphere|null $associationEntity */
                    $associationEntity = $associationRepository->findOneBy(['name' => $value]);
                    if (!$associationEntity) {
                        if ('type' === $colName && 'Other' == $value) {
                            // legacy code
                            $value = 'Contract';
                            /** @var Type|null $associationEntity */
                            $associationEntity = $associationRepository->findOneBy(['name' => $value]);
                            if (!$associationEntity) {
                                throw new \Exception("cannot find entity [{$colName}] of name [{$value}]");
                            }
                        } else {
                            throw new \Exception("cannot find entity [{$colName}] of name [{$value}]");
                        }
                    }

                    if (!$cardEntity->{$getter}() || $cardEntity->{$getter}()->getId() !== $associationEntity->getId()) {
                        $changed = true;
                        $cardEntity->{$setter}($associationEntity);
                    }
                } elseif (in_array($colName, $cardFieldNames)) {
                    // Scalar field on Card.
                    $type = $cardMeta->getTypeOfField($colName);
                    if ('boolean' === $type) {
                        $value = (bool) $value;
                    } elseif ('smallint' === $type && '' == $value) {
                        $value = null;
                    } elseif ('smallint' === $type && 'X' == $value) {
                        $value = null;
                    } elseif ('cost' === $colName && '' == $value) {
                        $value = null;
                    }

                    if ($cardEntity->{$getter}() !== $value) {
                        $changed = true;
                        $cardEntity->{$setter}($value);
                    }
                } elseif (in_array($colName, $printingFieldNames)) {
                    // Scalar field on CardPrinting (quantity, illustrator, imageCode, …).
                    $type = $printingMeta->getTypeOfField($colName);
                    if ('boolean' === $type) {
                        $value = (bool) $value;
                    } elseif ('smallint' === $type && '' == $value) {
                        $value = null;
                    } elseif ('smallint' === $type && 'X' == $value) {
                        $value = null;
                    } elseif ('cost' === $colName && '' == $value) {
                        $value = null;
                    }

                    if ($printingEntity->{$getter}() !== $value) {
                        $changed = true;
                        $printingEntity->{$setter}($value);
                    }
                }
            }
        }

        $this->entityManager->flush();

        return new Response('Done');
    }
}
