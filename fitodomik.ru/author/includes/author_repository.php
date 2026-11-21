<?php
declare(strict_types=1);
function fitodomikLoadAuthorContent(PDO $pdo): array
{
    $profileStmt = $pdo->prepare("
        SELECT profile_key, full_name, public_handle, tagline, intro, biography,
               location_text, status_text, avatar_path
        FROM author_profile
        WHERE profile_key = ? AND is_public = 1
        LIMIT 1
    ");
    $profileStmt->execute(['vyacheslav-domnin']);
    $projectsStmt = $pdo->query("
        SELECT slug, title, summary, stack_text, status_text, live_url, repo_url
        FROM author_projects
        WHERE is_public = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $skillsStmt = $pdo->query("
        SELECT skill_key, category_name, title, summary
        FROM author_skills
        WHERE is_public = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $milestonesStmt = $pdo->query("
        SELECT milestone_key, period_text, title, summary
        FROM author_milestones
        WHERE is_public = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $linksStmt = $pdo->query("
        SELECT link_key, label, display_value, url, icon_name
        FROM author_links
        WHERE is_public = 1
        ORDER BY sort_order ASC, id ASC
    ");
    return [
        'profile' => $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [],
        'projects' => $projectsStmt ? $projectsStmt->fetchAll(PDO::FETCH_ASSOC) : [],
        'skills' => $skillsStmt ? $skillsStmt->fetchAll(PDO::FETCH_ASSOC) : [],
        'milestones' => $milestonesStmt ? $milestonesStmt->fetchAll(PDO::FETCH_ASSOC) : [],
        'links' => $linksStmt ? $linksStmt->fetchAll(PDO::FETCH_ASSOC) : [],
    ];
}
function fitodomikAuthorFallbackContent(): array
{
    $seeds = fitodomikAuthorSeedData();
    return [
        'profile' => $seeds['profile'][0],
        'projects' => $seeds['projects'],
        'skills' => $seeds['skills'],
        'milestones' => $seeds['milestones'],
        'links' => $seeds['links'],
    ];
}
