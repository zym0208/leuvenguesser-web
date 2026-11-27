<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\GameResult;
use App\Entity\GameResultRound;
use App\Repository\ChallengeRepository;
use App\Repository\DuelQueueRepository;
use App\Repository\DuelRepository;
use App\Repository\FriendInvitationRepository;
use App\Repository\FriendRepository;
use App\Repository\GameResultRepository;
use App\Repository\LocationRepository;
use App\Repository\RegionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for write and admin paths in repositories.
 * These may modify the test database.
 */
final class RepositoryWriteTest extends KernelTestCase
{
    // ── ChallengeRepository – admin & write paths ─────────────────────────────

    public function testChallengeListForAdminReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSetsForAdmin([]);

        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('id', $rows[0]);
            self::assertArrayHasKey('published', $rows[0]);
        }
    }

    public function testChallengeListForAdminWithPublishedFilter(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSetsForAdmin(['status' => 'published']);

        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertTrue($row['published']);
        }
    }

    public function testChallengeListForAdminWithHiddenFilter(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSetsForAdmin(['status' => 'hidden']);

        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertFalse($row['published']);
        }
    }

    public function testChallengeListForAdminWithTextFilter(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSetsForAdmin(['q' => 'leuven']);

        self::assertIsArray($rows);
    }

    public function testChallengeFindForAdminNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertNull($repo->findChallengeSetForAdmin(999999999));
    }

    public function testChallengeSetPublishedForAdminNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->setChallengeSetPublishedForAdmin(999999999, true));
    }

    public function testChallengeDeleteForAdminNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->deleteChallengeSetForAdmin(999999999));
    }

    public function testChallengeDeleteCommentForAdminNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->deleteCommentForAdmin(999999999));
    }

    public function testChallengeRecordPlayForNonexistentSetDoesNotThrow(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $repo->recordChallengeSetPlay(999999999);

        self::assertTrue(true);
    }

    public function testChallengeToggleLikeForNonexistentSetReturnsDefaults(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $result = $repo->toggleLike(999999999, 1);

        self::assertFalse($result['liked']);
        self::assertSame(0, $result['like_count']);
    }

    public function testChallengeAddCommentForNonexistentSetDoesNotThrow(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $repo->addComment(999999999, 1, 'Tester', 'hello');

        self::assertTrue(true);
    }

    public function testChallengeListSortNewest(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['sort' => 'newest'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeListSortMostPlayed(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['sort' => 'most_played'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeListSortMostLiked(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['sort' => 'most_liked'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeListSortTrending(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['sort' => 'trending'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeListSortPopular(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['sort' => 'popular'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeListWithRegionFilter(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets(['region' => 'leuven'], null);

        self::assertIsArray($rows);
    }

    public function testChallengeCreateAndPublish(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);
        $regionRepo = static::getContainer()->get(RegionRepository::class);

        $regionId = $regionRepo->findOrCreateRegion('Test Region Write', 50.88, 4.70);
        $setId = $repo->createChallengeSet('Test Challenge Write', 'desc', $regionId, 'Test Author', 1);

        self::assertGreaterThan(0, $setId);

        $published = $repo->setChallengeSetPublishedForAdmin($setId, true);
        self::assertTrue($published);

        $row = $repo->findChallengeSetForAdmin($setId);
        self::assertNotNull($row);
        self::assertSame('Test Challenge Write', $row['title']);
        self::assertTrue($row['published']);

        // Clean up
        $repo->deleteChallengeSetForAdmin($setId);
    }

    public function testChallengeIsOwnedByUserWithMatchingAuthor(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);
        $regionRepo = static::getContainer()->get(RegionRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $authorUserId = (int) $users[0]->getId();
        $regionId = $regionRepo->findOrCreateRegion('Ownership Test Region', 51.0, 4.0);
        $setId = $repo->createChallengeSet('Ownership Test', null, $regionId, 'Author', $authorUserId);

        self::assertTrue($repo->isOwnedByUser($setId, $authorUserId));
        self::assertFalse($repo->isOwnedByUser($setId, $authorUserId + 999_999));

        // Clean up
        $repo->deleteChallengeSetForAdmin($setId);
    }

    public function testChallengeFindChallengeSetWithExistingPublishedId(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets([], null);
        if ($rows === []) {
            self::markTestIncomplete('No published challenges in DB.');
        }

        $id = $rows[0]['id'];
        $detail = $repo->findChallengeSet($id, null);

        self::assertNotNull($detail);
        self::assertArrayHasKey('locations', $detail);
        self::assertArrayHasKey('comments', $detail);
    }

    public function testChallengeDeleteOwnedNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->deleteOwnedChallengeSet(999999999, 1));
    }

    public function testChallengeUpdateOwnedNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->updateOwnedChallengeSet(999999999, 1, 'new title', null, null));
    }

    public function testChallengeCoordinatePointsForNonexistentSetReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $points = $repo->coordinatePointsForOwnedChallengeSet(999999999, 1);

        self::assertSame([], $points);
    }

    public function testChallengeFindRegionByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertNull($repo->findRegionById(999999999));
    }

    public function testChallengeFindRegionBySlugNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertNull($repo->findRegionBySlug('__nonexistent_slug__'));
    }

    public function testChallengeRefreshOwnedRegionNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->refreshOwnedChallengeRegionFromLocations(999999999, 1));
    }

    public function testChallengeDeleteOwnedLocationOnNonexistentSetReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->deleteOwnedChallengeLocation(999999999, 1, 999999999));
    }

    // ── LocationRepository – write paths ──────────────────────────────────────

    public function testLocationCreateUploadedLocation(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        $id = $repo->createUploadedLocation(50.88, 4.70, 'upload-' . uniqid(), 'image/jpeg', 'fake-binary-data');

        self::assertGreaterThan(0, $id);
    }

    public function testLocationFindRandomForNonexistentRegionReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        $result = $repo->findRandomForRegionRoundExcluding('__nonexistent_region_slug__', []);

        self::assertNull($result);
    }

    public function testLocationFindRandomExcludingEmptyList(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        // Should return null or a row — just ensure it doesn't throw
        $result = $repo->findRandomForRoundExcluding([]);

        self::assertTrue($result === null || is_array($result));
    }

    // ── RegionRepository – findRegionRowForChallengeLocation ──────────────────

    public function testRegionFindRowForChallengeLocationNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        self::assertNull($repo->findRegionRowForChallengeLocation(999999999, 999999999));
    }

    // ── DuelRepository – full write coverage ─────────────────────────────────

    public function testDuelSetLocation(): void
    {
        self::bootKernel();
        $duelRepo = static::getContainer()->get(DuelRepository::class);
        $locationRepo = static::getContainer()->get(LocationRepository::class);

        $duel = $duelRepo->addDuel(100, 200);
        $locationId = $locationRepo->createUploadedLocation(51.0, 4.0, 'upload-' . uniqid(), 'image/jpeg', 'data');
        $updated = $duelRepo->setDuelLocation($duel->getId(), $locationId);

        self::assertSame($locationId, $updated->getLocation());
    }

    public function testDuelSetStatus(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelRepository::class);

        $duel = $repo->addDuel(300, 400);
        $updated = $repo->setStatus($duel->getId(), 'finished');

        self::assertSame('finished', $updated->getStatus());
    }

    public function testDuelSetP2Score(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelRepository::class);

        $duel = $repo->addDuel(500, 600);
        $updated = $repo->setScore($duel->getId(), 3000, false);

        self::assertSame(3000, $updated->getP2Score());
        self::assertTrue($updated->isP2Finish());
    }

    // ── DuelQueueRepository – setMatchWith and setResultMatchId ───────────────

    public function testDuelQueueSetMatchWith(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $entry = $repo->addQueueEntry(111111);
        $repo->setMatchWith($entry->getId(), 222222);

        $updated = $repo->getLatestUserEntry(111111);
        self::assertNotNull($updated);
        self::assertSame(222222, $updated->getMatchedWith());

        $repo->removeOldUserEntries(111111);
    }

    public function testDuelQueueSetResultMatchId(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $entry = $repo->addQueueEntry(333333);
        $repo->setResultMatchId($entry->getId(), 999);

        $updated = $repo->getLatestUserEntry(333333);
        self::assertNotNull($updated);
        self::assertSame(999, $updated->getResultMatch());

        $repo->removeOldUserEntries(333333);
    }

    public function testDuelQueueSetMatchWithNonexistentEntryDoesNotThrow(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $repo->setMatchWith(999999999, 1);

        self::assertTrue(true);
    }

    public function testDuelQueueSetResultMatchIdNonexistentEntryDoesNotThrow(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $repo->setResultMatchId(999999999, 1);

        self::assertTrue(true);
    }

    // ── GameResultRepository – persistNewResultWithRounds ─────────────────────

    public function testGameResultPersistNewResult(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        $round = (new GameResultRound())
            ->setLocationId(1)
            ->setScore(5000)
            ->setDistanceMeters(0.0)
            ->setRoundNumber(0);

        $result = (new GameResult())
            ->setMode('daily')
            ->setUserId(1)
            ->setTotalScore(5000)
            ->addRound($round);

        $repo->persistNewResultWithRounds($result);

        self::assertNotNull($result->getId());
    }

    public function testGameResultPersistWithUserId(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);
        $userRepo = static::getContainer()->get(\App\Repository\UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = (int) $users[0]->getId();

        $result = (new GameResult())
            ->setMode('daily')
            ->setTotalScore(9999999)
            ->setUserId($userId);

        $repo->persistNewResultWithRounds($result);
        self::assertNotNull($result->getId());

        // leaderboardRows exercises the 'u' identity branch when userId-based results appear.
        // Score 9999999 guarantees top-10 placement so the user-lookup code always runs.
        $rows = $repo->leaderboardRows('daily', null);
        self::assertIsArray($rows);
        self::assertNotEmpty($rows, 'Leaderboard must not be empty after inserting a 9999999-score result.');
    }

    public function testGameResultLeaderboardForNonexistentChallengeSetReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        // Challenge set 999999999 has no results → exercises the empty early-return branch.
        $rows = $repo->leaderboardRows('challenge', 999999999);
        self::assertSame([], $rows);
    }

    public function testGameResultPreviousBestScoreWithUserId(): void
    {
        self::bootKernel();
        $repo     = static::getContainer()->get(GameResultRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = (int) $users[0]->getId();

        // challengeSetId = null, userId != null  →  exercises that branch in previousBestTotalScore
        $score = $repo->previousBestTotalScore('daily', null, $userId);
        self::assertTrue($score === null || is_int($score));
    }

    public function testGameResultPreviousBestScoreWithChallengeSetIdAndUserId(): void
    {
        self::bootKernel();
        $repo     = static::getContainer()->get(GameResultRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = (int) $users[0]->getId();

        // challengeSetId != null, userId != null  →  exercises that combined branch
        $score = $repo->previousBestTotalScore('challenge', 999999999, $userId);
        self::assertNull($score);
    }

    // ── UserRepository – listForAdmin and findUserById ────────────────────────

    public function testUserListForAdmin(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        $rows = $repo->listForAdmin();
        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('id', $rows[0]);
            self::assertArrayHasKey('name', $rows[0]);
            self::assertArrayHasKey('roles', $rows[0]);
            self::assertArrayHasKey('isAdmin', $rows[0]);
        }
    }

    public function testUserFindById(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        $users = $repo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = (int) $users[0]->getId();

        $found = $repo->findUserById($userId);
        self::assertNotNull($found);
        self::assertSame($userId, $found->getId());

        self::assertNull($repo->findUserById(999999999));
    }

    // ── FriendRepository – missing branches in createFriendship ──────────────

    public function testFriendCreateFriendshipSelfThrows(): void
    {
        self::bootKernel();
        $repo  = static::getContainer()->get(FriendRepository::class);
        $users = static::getContainer()->get(UserRepository::class)->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $repo->createFriendship((int) $users[0]->getId(), (int) $users[0]->getId());
    }

    public function testFriendCreateFriendshipUserNotFoundThrows(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $repo->createFriendship(999999998, 999999999);
    }

    public function testFriendCreateFriendshipAlreadyFriendsIsNoOp(): void
    {
        self::bootKernel();
        $repo  = static::getContainer()->get(FriendRepository::class);
        $users = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userId   = (int) $users[0]->getId();
        $friendId = (int) $users[1]->getId();

        // Ensure clean state before the test.
        $repo->removeFriendship($userId, $friendId);

        $repo->createFriendship($userId, $friendId);
        self::assertTrue($repo->areFriends($userId, $friendId));

        // Second call must be a no-op (hits the early-return branch).
        $repo->createFriendship($userId, $friendId);
        self::assertTrue($repo->areFriends($userId, $friendId));

        // Clean up.
        $repo->removeFriendship($userId, $friendId);
    }

    public function testFriendListFriendsWithRealFriendship(): void
    {
        self::bootKernel();
        $repo    = static::getContainer()->get(FriendRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);
        $users   = $userRepo->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userId   = (int) $users[0]->getId();
        $friendId = (int) $users[1]->getId();

        // Ensure clean state, then create friendship.
        $repo->removeFriendship($userId, $friendId);
        $repo->createFriendship($userId, $friendId);

        // listFriendsForUser foreach body is only executed when friends exist.
        $friends = $repo->listFriendsForUser($userId);
        self::assertNotEmpty($friends);
        self::assertArrayHasKey('id', $friends[0]);
        self::assertArrayHasKey('name', $friends[0]);
        self::assertArrayHasKey('rank', $friends[0]);
        self::assertArrayHasKey('leuven_score', $friends[0]);
        self::assertArrayHasKey('challenge_score', $friends[0]);
        self::assertArrayHasKey('duel_score', $friends[0]);

        // Clean up.
        $repo->removeFriendship($userId, $friendId);
    }

    // ── FriendInvitationRepository – fully covering all 4 uncovered methods ────

    private function cleanupInvitations(FriendInvitationRepository $invRepo, FriendRepository $friendRepo, int $userA, int $userB): void
    {
        $friendRepo->removeFriendship($userA, $userB);
        $a2b = $invRepo->findPendingBetween($userA, $userB);
        if ($a2b !== null) {
            $invRepo->cancelInvitation($a2b->getId(), $userA);
        }
        $b2a = $invRepo->findPendingBetween($userB, $userA);
        if ($b2a !== null) {
            $invRepo->cancelInvitation($b2a->getId(), $userB);
        }
    }

    public function testFriendInvitationHasPendingBetween(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        // No invitation yet → false.
        self::assertFalse($invRepo->hasPendingBetween($userA, $userB));

        // Send invitation → true.
        $invRepo->sendInvitation($userA, $userB);
        self::assertTrue($invRepo->hasPendingBetween($userA, $userB));

        // Clean up.
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
    }

    public function testFriendInvitationSendUserNotFoundThrows(): void
    {
        self::bootKernel();
        $invRepo = static::getContainer()->get(FriendInvitationRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $invRepo->sendInvitation(999999998, 999999999);
    }

    public function testFriendInvitationSendAlreadyPendingThrows(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);

        try {
            $invRepo->sendInvitation($userA, $userB);
            self::fail('Expected InvalidArgumentException for duplicate invitation.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already pending', $e->getMessage());
        } finally {
            $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
        }
    }

    public function testFriendInvitationSendReversePendingThrows(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        // Send A → B, then try B → A (reverse direction).
        $invRepo->sendInvitation($userA, $userB);

        try {
            $invRepo->sendInvitation($userB, $userA);
            self::fail('Expected InvalidArgumentException for reverse invitation.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already sent', $e->getMessage());
        } finally {
            $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
        }
    }

    public function testFriendInvitationCancelSuccess(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);
        $invitation = $invRepo->findPendingBetween($userA, $userB);
        self::assertNotNull($invitation);

        // cancelInvitation success path.
        $result = $invRepo->cancelInvitation($invitation->getId(), $userA);
        self::assertTrue($result);

        // Verify the invitation is gone.
        self::assertNull($invRepo->findPendingBetween($userA, $userB));
    }

    public function testFriendInvitationAcceptWrongRecipientThrows(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);
        $invitation = $invRepo->findPendingBetween($userA, $userB);
        self::assertNotNull($invitation);

        // Accept with wrong recipient (userA sent to userB, but userA tries to accept).
        try {
            $invRepo->acceptInvitation($invitation->getId(), $userA);
            self::fail('Expected InvalidArgumentException for wrong recipient.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('accept', $e->getMessage());
        } finally {
            $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
        }
    }

    public function testFriendInvitationAcceptSuccess(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);
        $invitation = $invRepo->findPendingBetween($userA, $userB);
        self::assertNotNull($invitation);

        // acceptInvitation success path: creates friendship, removes invitation.
        $invRepo->acceptInvitation($invitation->getId(), $userB);

        // Invitation is removed.
        self::assertNull($invRepo->findPendingBetween($userA, $userB));

        // Friendship is created.
        self::assertTrue($friendRepo->areFriends($userA, $userB));

        // Clean up.
        $friendRepo->removeFriendship($userA, $userB);
    }

    public function testCancelInvitationByUnrelatedActorReturnsFalse(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $userRepo   = static::getContainer()->get(UserRepository::class);
        $users      = $userRepo->topByTotalScore(3);
        if (count($users) < 3) {
            self::markTestIncomplete('Need at least 3 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $userC = (int) $users[2]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);
        $invitation = $invRepo->findPendingBetween($userA, $userB);
        self::assertNotNull($invitation);

        // userC is unrelated — cancelInvitation must return false (line 133).
        $result = $invRepo->cancelInvitation($invitation->getId(), $userC);
        self::assertFalse($result);

        // Invitation still exists.
        self::assertNotNull($invRepo->findPendingBetween($userA, $userB));

        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
    }

    // ── GameResultRepository – uncovered paths ────────────────────────────────

    public function testPlayCountsLast24HoursWithEmptyIdsReturnsEmptyArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        // Empty input → early-return [] at line 145-146.
        $result = $repo->playCountsLast24HoursByChallengeSetIds([]);
        self::assertSame([], $result);
    }

    public function testRecentLocationIdsEarlyReturns(): void
    {
        self::bootKernel();
        $em   = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $repo = static::getContainer()->get(GameResultRepository::class);

        // Case 1: invalid userId → line 173-174 early return.
        $result = $repo->recentLocationIdsForUser(0);
        self::assertSame([], $result);

        // Case 2: valid userId with NO game results → lines 187-188 early return.
        $user = (new \App\Entity\User())
            ->setName('ri_nogrm_' . substr(uniqid('', true), 0, 8))
            ->setHashpass('x');
        $em->persist($user);
        $em->flush();

        $result = $repo->recentLocationIdsForUser((int) $user->getId());
        self::assertSame([], $result);
    }

    public function testRecentLocationIdsLoopBodyCoversAllBranches(): void
    {
        self::bootKernel();
        $em       = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $repo     = static::getContainer()->get(GameResultRepository::class);
        $locRepo  = static::getContainer()->get(LocationRepository::class);

        // Use a fresh user with no prior game results so count assertions are deterministic.
        $user = (new \App\Entity\User())
            ->setName('ri_loop_' . substr(uniqid('', true), 0, 8))
            ->setHashpass('x');
        $em->persist($user);
        $em->flush();
        $userId = (int) $user->getId();

        // Create 4 real locations (location_id has a DB FK → Locations.id).
        $lids = [];
        for ($i = 0; $i < 4; $i++) {
            $lids[] = $locRepo->createUploadedLocation(
                50.88, 4.70,
                'ri_loop_loc_' . $i . '_' . uniqid('', true),
                'image/jpeg',
                'FAKE'
            );
        }

        // Rounds (queried in roundNumber DESC order by the repository):
        //   5 → lids[0],  4 → lids[1],  3 → lids[0] (dup),
        //   2 → lids[2],  1 → lids[3]
        $gameResult = (new GameResult())
            ->setMode('leuven_challenge')
            ->setUserId($userId)
            ->setTotalScore(2500)
            ->setRoundCount(5);

        foreach ([1 => $lids[3], 2 => $lids[2], 3 => $lids[0], 4 => $lids[1], 5 => $lids[0]] as $num => $locId) {
            $round = (new GameResultRound())
                ->setRoundNumber($num)
                ->setScore(500)
                ->setLocationId($locId);
            $gameResult->addRound($round);
        }
        $em->persist($gameResult);
        $em->flush();

        try {
            // Call 1: limit=2 → after adding 2 unique IDs the break (line 216) fires.
            $ids = $repo->recentLocationIdsForUser($userId, 2);
            self::assertCount(2, $ids);

            // Call 2: limit=10 → round 3 is a duplicate (lids[0] already seen) → continue (line 209).
            $ids = $repo->recentLocationIdsForUser($userId, 10);
            self::assertCount(4, $ids); // unique: lids[0], lids[1], lids[2], lids[3]
        } finally {
            // Remove GameResult first (cascades to GameResultRounds via DB ON DELETE CASCADE).
            $em->remove($gameResult);
            $em->flush();
            // Then remove the temporary locations.
            foreach ($lids as $lid) {
                $location = $em->find(\App\Entity\Location::class, $lid);
                if ($location !== null) {
                    $em->remove($location);
                }
            }
            $em->flush();
        }
    }

    public function testListIncomingWithRealPendingInvitationCoversRowMapper(): void
    {
        self::bootKernel();
        $invRepo    = static::getContainer()->get(FriendInvitationRepository::class);
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $users      = static::getContainer()->get(UserRepository::class)->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $userA = (int) $users[0]->getId();
        $userB = (int) $users[1]->getId();
        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);

        $invRepo->sendInvitation($userA, $userB);

        // listIncoming for userB returns the invitation row and runs the array_map closure.
        $incoming = $invRepo->listIncoming($userB);
        self::assertNotEmpty($incoming);
        self::assertArrayHasKey('id', $incoming[0]);
        self::assertArrayHasKey('name', $incoming[0]);
        self::assertArrayHasKey('created_at', $incoming[0]);

        // listOutgoing for userA also runs the closure.
        $outgoing = $invRepo->listOutgoing($userA);
        self::assertNotEmpty($outgoing);

        $this->cleanupInvitations($invRepo, $friendRepo, $userA, $userB);
    }
}
