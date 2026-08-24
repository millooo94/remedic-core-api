<?php

namespace App\Enums;

enum ScientificContributionType: string
{
    case ScientificArticle = 'scientific_article';
    case ClinicalInsight = 'clinical_insight';
    case ScientificTalk = 'scientific_talk';
    case EducationalContribution = 'educational_contribution';
    case Teaching = 'teaching';
    case Conference = 'conference';
    case Other = 'other';
}
