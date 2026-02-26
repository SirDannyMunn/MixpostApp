<?php

namespace App\Services\Pipeline;

use LaundryOS\SocialWatcher\Models\ContentAnnotation;
use LaundryOS\SocialWatcher\Models\ContentNode;

class PipelineFormatVerifier
{
    /**
     * Verify canonical DB structure for content nodes created in a pipeline test run.
     *
     * @param array<int, string> $contentNodeIds
     */
    public function verify(ProfileContext $profile, array $contentNodeIds): FormatVerificationResult
    {
        if (!$profile->persist) {
            return new FormatVerificationResult(
                status: 'skipped',
                checks: [
                    [
                        'code' => 'profile.persist',
                        'status' => 'skipped',
                        'message' => 'Profile does not persist; format verification skipped.',
                        'details' => [
                            'profile' => $profile->name,
                        ],
                    ],
                ],
                stats: [
                    'content_node_ids' => count($contentNodeIds),
                ]
            );
        }

        $checks = [];
        $errors = [];
        $warnings = [];

        $addCheck = function (string $code, string $status, string $message, array $details = []) use (&$checks): void {
            $check = [
                'code' => $code,
                'status' => $status,
                'message' => $message,
            ];
            if (!empty($details)) {
                $check['details'] = $details;
            }
            $checks[] = $check;
        };

        if (empty($contentNodeIds)) {
            $errors[] = 'No content nodes were created for this test run.';
            $addCheck('content_nodes.exists', 'fail', 'Expected content nodes but found none.');

            return new FormatVerificationResult(
                status: 'fail',
                checks: $checks,
                errors: $errors,
                warnings: $warnings,
                stats: [
                    'content_nodes' => 0,
                ],
            );
        }

        $nodes = ContentNode::whereIn('id', $contentNodeIds)
            ->with([
                'parent:id',
                'metrics:id,content_node_id,version',
                'fragments:id,content_node_id',
                'embeddings:id,content_node_id,fragment_id,purpose,model',
                'contentAnnotations:id,content_node_id,fragment_id,annotation_type,schema_version',
                'parsed:id,content_node_id,parser,parser_version',
            ])
            ->get();

        $missingIds = array_values(array_diff($contentNodeIds, $nodes->pluck('id')->all()));
        if (!empty($missingIds)) {
            $errors[] = 'Some content node IDs were not found in the database.';
            $addCheck('content_nodes.missing', 'fail', 'Content node IDs missing from DB.', [
                'missing_ids' => $missingIds,
            ]);
        } else {
            $addCheck('content_nodes.loaded', 'pass', 'All content nodes loaded from DB.', [
                'count' => $nodes->count(),
            ]);
        }

        // 1) Required fields + basic invariants
        $nodesMissingRequired = [];
        $transcriptCandidates = 0;
        $transcriptWithText = 0;

        foreach ($nodes as $node) {
            $missing = [];

            if (empty($node->organization_id)) {
                $missing[] = 'organization_id';
            }
            if (empty($node->platform)) {
                $missing[] = 'platform';
            }
            if (empty($node->content_type)) {
                $missing[] = 'content_type';
            }
            if (empty($node->source_id)) {
                $missing[] = 'source_id';
            }
            if ($node->raw_payload === null || $node->raw_payload === []) {
                $missing[] = 'raw_payload';
            }
            if (!is_array($node->metadata)) {
                $missing[] = 'metadata';
            }

            if (!empty($missing)) {
                $nodesMissingRequired[] = [
                    'id' => $node->id,
                    'missing' => $missing,
                ];
            }

            if ($profile->expectsTranscriptText()) {
                $transcriptCandidates++;
                if (is_string($node->text) && trim($node->text) !== '' && mb_strlen(trim($node->text)) >= 50) {
                    $transcriptWithText++;
                }
            }
        }

        if (!empty($nodesMissingRequired)) {
            $errors[] = 'One or more content nodes are missing required fields.';
            $addCheck('content_nodes.required_fields', 'fail', 'Required fields missing on some content nodes.', [
                'nodes' => $nodesMissingRequired,
            ]);
        } else {
            $addCheck('content_nodes.required_fields', 'pass', 'All content nodes have required fields.');
        }

        if ($profile->expectsTranscriptText()) {
            if ($transcriptWithText === 0) {
                $message = 'Transcript profile expected extracted text on at least one node, but none had sufficient text.';
                if ($profile->strict) {
                    $errors[] = $message;
                    $addCheck('content_nodes.transcript_text', 'fail', $message, [
                        'nodes_checked' => $transcriptCandidates,
                    ]);
                } else {
                    $warnings[] = $message;
                    $addCheck('content_nodes.transcript_text', 'warn', $message, [
                        'nodes_checked' => $transcriptCandidates,
                    ]);
                }
            } else {
                $addCheck('content_nodes.transcript_text', 'pass', 'Found transcript-like text on at least one node.', [
                    'nodes_with_text' => $transcriptWithText,
                    'nodes_checked' => $transcriptCandidates,
                ]);
            }
        }

        // 1b) Normalization coverage checks (canonical columns)
        $missingCanonicalUrl = [];
        $missingExternalId = [];
        $missingPublishedAt = [];
        $invalidMetrics = [];

        foreach ($nodes as $node) {
            if ($this->shouldExpectCanonicalUrl($node) && empty($node->canonical_url)) {
                $missingCanonicalUrl[] = $node->id;
            }
            if (empty($node->external_id)) {
                $missingExternalId[] = $node->id;
            }
            if ($this->shouldExpectPublishedAt($node) && empty($node->published_at)) {
                $missingPublishedAt[] = $node->id;
            }

            foreach (['like_count', 'comment_count', 'share_count', 'view_count'] as $field) {
                $val = $node->{$field} ?? null;
                if ($val !== null && !is_numeric($val)) {
                    $invalidMetrics[] = [
                        'id' => $node->id,
                        'field' => $field,
                        'value' => $val,
                    ];
                }
            }
        }

        if (!empty($missingCanonicalUrl)) {
            $message = 'Some content nodes are missing canonical_url fields.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('content_nodes.canonical_url', 'fail', $message, [
                    'count' => count($missingCanonicalUrl),
                    'sample_ids' => array_slice($missingCanonicalUrl, 0, 5),
                ]);
            } else {
                $warnings[] = $message;
                $addCheck('content_nodes.canonical_url', 'warn', $message, [
                    'count' => count($missingCanonicalUrl),
                    'sample_ids' => array_slice($missingCanonicalUrl, 0, 5),
                ]);
            }
        } else {
            $addCheck('content_nodes.canonical_url', 'pass', 'Canonical URLs populated for expected nodes.');
        }

        if (!empty($missingExternalId)) {
            $message = 'Some content nodes are missing external_id fields.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('content_nodes.external_id', 'fail', $message, [
                    'count' => count($missingExternalId),
                    'sample_ids' => array_slice($missingExternalId, 0, 5),
                ]);
            } else {
                $warnings[] = $message;
                $addCheck('content_nodes.external_id', 'warn', $message, [
                    'count' => count($missingExternalId),
                    'sample_ids' => array_slice($missingExternalId, 0, 5),
                ]);
            }
        } else {
            $addCheck('content_nodes.external_id', 'pass', 'External IDs populated for content nodes.');
        }

        if (!empty($missingPublishedAt)) {
            $message = 'Some content nodes are missing published_at values.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('content_nodes.published_at', 'fail', $message, [
                    'count' => count($missingPublishedAt),
                    'sample_ids' => array_slice($missingPublishedAt, 0, 5),
                ]);
            } else {
                $warnings[] = $message;
                $addCheck('content_nodes.published_at', 'warn', $message, [
                    'count' => count($missingPublishedAt),
                    'sample_ids' => array_slice($missingPublishedAt, 0, 5),
                ]);
            }
        } else {
            $addCheck('content_nodes.published_at', 'pass', 'Published dates present for expected nodes.');
        }

        if (!empty($invalidMetrics)) {
            $message = 'Some content nodes have non-numeric metric values.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('content_nodes.metrics', 'fail', $message, [
                    'count' => count($invalidMetrics),
                    'sample' => array_slice($invalidMetrics, 0, 5),
                ]);
            } else {
                $warnings[] = $message;
                $addCheck('content_nodes.metrics', 'warn', $message, [
                    'count' => count($invalidMetrics),
                    'sample' => array_slice($invalidMetrics, 0, 5),
                ]);
            }
        } else {
            $addCheck('content_nodes.metrics', 'pass', 'Metric columns are numeric or null.');
        }

        // 2) Parent linkage integrity
        $parentIds = $nodes->pluck('parent_id')->filter()->unique()->values()->all();
        if (!empty($parentIds)) {
            $parentsFound = ContentNode::whereIn('id', $parentIds)->count();
            if ($parentsFound !== count($parentIds)) {
                $errors[] = 'One or more parent_id references are broken.';
                $addCheck('graph.parent_links', 'fail', 'Some parent_id references do not resolve.', [
                    'unique_parent_ids' => count($parentIds),
                    'parents_found' => $parentsFound,
                ]);
            } else {
                $addCheck('graph.parent_links', 'pass', 'All parent_id references resolve.', [
                    'unique_parent_ids' => count($parentIds),
                ]);
            }
        } else {
            $addCheck('graph.parent_links', 'pass', 'No parent links to validate (all nodes are roots).');
        }

        // 3) Metrics existence (best-effort, strict escalates)
        $nodesWithMetrics = $nodes->filter(fn($n) => $n->metrics->isNotEmpty())->count();
        if ($nodesWithMetrics === 0) {
            $message = 'No content metrics found for created nodes.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('metrics.exists', 'fail', $message);
            } else {
                $warnings[] = $message;
                $addCheck('metrics.exists', 'warn', $message);
            }
        } else {
            $addCheck('metrics.exists', 'pass', 'Found content metrics for at least one node.', [
                'nodes_with_metrics' => $nodesWithMetrics,
                'nodes_total' => $nodes->count(),
            ]);
        }

        // 4) Fragments existence (best-effort)
        $nodesWithFragments = $nodes->filter(fn($n) => $n->fragments->isNotEmpty())->count();
        if ($nodesWithFragments === 0) {
            $message = 'No content fragments found for created nodes.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('fragments.exists', 'fail', $message);
            } else {
                $warnings[] = $message;
                $addCheck('fragments.exists', 'warn', $message);
            }
        } else {
            $addCheck('fragments.exists', 'pass', 'Found content fragments for at least one node.', [
                'nodes_with_fragments' => $nodesWithFragments,
                'nodes_total' => $nodes->count(),
            ]);
        }

        // 5) Embeddings existence (best-effort)
        $embeddingsTotal = $nodes->sum(fn($n) => $n->embeddings->count());
        $fragmentEmbeddings = $nodes->sum(fn($n) => $n->embeddings->whereNotNull('fragment_id')->count());

        if ($embeddingsTotal === 0) {
            $message = 'No embeddings found for created nodes/fragments.';
            if ($profile->strict) {
                $errors[] = $message;
                $addCheck('embeddings.exists', 'fail', $message);
            } else {
                $warnings[] = $message;
                $addCheck('embeddings.exists', 'warn', $message);
            }
        } else {
            $addCheck('embeddings.exists', 'pass', 'Found embeddings for created nodes/fragments.', [
                'total_embeddings' => $embeddingsTotal,
                'fragment_embeddings' => $fragmentEmbeddings,
            ]);
        }

        // 6) Annotations existence (optional; honor --skip-annotations)
        if ($profile->skipAnnotations) {
            $addCheck('annotations.exists', 'skipped', 'Annotation verification skipped via --skip-annotations.');
        } else {
            $topicCount = $nodes->sum(fn($n) => $n->contentAnnotations->where('annotation_type', ContentAnnotation::TYPE_TOPIC)->count());
            $creativeCount = $nodes->sum(fn($n) => $n->contentAnnotations->where('annotation_type', ContentAnnotation::TYPE_CREATIVE)->count());

            if (($topicCount + $creativeCount) === 0) {
                $message = 'No topic/creative annotations found for created nodes.';
                if ($profile->strict) {
                    $errors[] = $message;
                    $addCheck('annotations.exists', 'fail', $message);
                } else {
                    $warnings[] = $message;
                    $addCheck('annotations.exists', 'warn', $message);
                }
            } else {
                $addCheck('annotations.exists', 'pass', 'Found topic/creative annotations for created nodes.', [
                    'topic' => $topicCount,
                    'creative' => $creativeCount,
                ]);
            }
        }

        // 7) Keyword-driven metadata presence (best-effort)
        if ($profile->isKeywordDriven()) {
            $nodesMissingKeywordMetadata = [];
            foreach ($nodes as $node) {
                $md = is_array($node->metadata) ? $node->metadata : [];
                $hasKeyword = false;

                foreach (['keyword_set_id', 'keyword', 'keywords', 'query', 'search_query', 'searchQueries', 'target_id'] as $key) {
                    if (array_key_exists($key, $md) && $md[$key] !== null && $md[$key] !== '') {
                        $hasKeyword = true;
                        break;
                    }
                }

                if (!$hasKeyword) {
                    $nodesMissingKeywordMetadata[] = $node->id;
                }
            }

            if (!empty($nodesMissingKeywordMetadata)) {
                $message = 'Keyword/search profile expected keyword metadata on nodes (best-effort), but some nodes lacked it.';
                if ($profile->strict) {
                    $errors[] = $message;
                    $addCheck('metadata.keyword_signals', 'fail', $message, [
                        'expected_from_defaults' => $profile->keywordSignals(),
                        'node_ids_missing_keyword_metadata' => $nodesMissingKeywordMetadata,
                    ]);
                } else {
                    $warnings[] = $message;
                    $addCheck('metadata.keyword_signals', 'warn', $message, [
                        'expected_from_defaults' => $profile->keywordSignals(),
                        'node_ids_missing_keyword_metadata' => $nodesMissingKeywordMetadata,
                    ]);
                }
            } else {
                $addCheck('metadata.keyword_signals', 'pass', 'All nodes have some keyword/search metadata signal.');
            }
        } else {
            $addCheck('metadata.keyword_signals', 'pass', 'Profile not keyword-driven; skipping keyword metadata expectations.');
        }

        // 8) Clusters (explicitly non-failing)
        $addCheck('clusters.optional', 'skipped', 'Cluster verification is non-blocking and not implemented for Phase 1.');

        $status = 'pass';
        if (!empty($errors)) {
            $status = 'fail';
        } elseif (!empty($warnings)) {
            $status = 'warn';
        }

        return new FormatVerificationResult(
            status: $status,
            checks: $checks,
            errors: $errors,
            warnings: $warnings,
            stats: [
                'content_nodes' => $nodes->count(),
                'nodes_with_metrics' => $nodesWithMetrics,
                'nodes_with_fragments' => $nodesWithFragments,
                'embeddings_total' => $embeddingsTotal,
                'annotations_total' => $nodes->sum(fn($n) => $n->contentAnnotations->count()),
            ],
        );
    }

    private function shouldExpectCanonicalUrl(ContentNode $node): bool
    {
        if ($node->content_type === 'comment') {
            return false;
        }

        return in_array(strtolower((string) $node->platform), [
            'x',
            'twitter',
            'youtube',
            'tiktok',
            'instagram',
            'linkedin',
        ], true);
    }

    private function shouldExpectPublishedAt(ContentNode $node): bool
    {
        return in_array(strtolower((string) $node->platform), [
            'x',
            'twitter',
            'youtube',
            'tiktok',
            'instagram',
            'linkedin',
        ], true);
    }
}
