<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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

final class RepositoryTest extends KernelTestCase
{
    // ── UserRepository ────────────────────────────────────────────────────────

    public function testUserFindByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        self::assertNull($repo->findUserById(999999999));
    }

    public function testUserFindByNameNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        self::assertNull($repo->findOneByName('__no_such_user_xyz__'));
    }

    public function testUserTopByTotalScoreReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        $results = $repo->topByTotalScore(5);

        self::assertIsArray($results);
        self::assertLessThanOrEqual(5, count($results));
    }

    public function testUserListForAdminReturnsExpectedKeys(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(UserRepository::class);

        $rows = $repo->listForAdmin(5);

        self::assertIsArray($rows);
        if ($rows !== []) {
            $first = $rows[0];
            self::assertArrayHasKey('id', $first);
            self::assertArrayHasKey('name', $first);
            self::assertArrayHasKey('roles', $first);
            self::assertArrayHasKey('isAdmin', $first);
        }
    }

    // ── RegionRepository ──────────────────────────────────────────────────────

    public function testRegionListRegionsAssociativeReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        $rows = $repo->listRegionsAssociative();

        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('id', $rows[0]);
            self::assertArrayHasKey('name', $rows[0]);
            self::assertArrayHasKey('slug', $rows[0]);
        }
    }

    public function testRegionFindByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        self::assertNull($repo->findRegionRowById(999999999));
    }

    public function testRegionFindByNonexistentSlugReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        self::assertNull($repo->findRegionRowBySlug('__nonexistent_slug_xyz__'));
    }

    public function testRegionFindOrCreateReturnsSameIdForSameName(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        $name = 'Test Region ' . uniqid();
        $id1 = $repo->findOrCreateRegion($name, 50.0, 4.0);
        $id2 = $repo->findOrCreateRegion($name, 50.0, 4.0);

        self::assertIsInt($id1);
        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    public function testRegionFindOrCreateUsesDefaultNameWhenEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        $id = $repo->findOrCreateRegion('', 51.1234, 3.5678);

        self::assertGreaterThan(0, $id);
        $row = $repo->findRegionRowById($id);
        self::assertNotNull($row);
        self::assertStringContainsString('51', (string) $row['name']);
    }

    public function testRegionFindOrCreateWithSpecialCharsOnlyNameUsesHashFallback(): void
    {
        // Triggers the slugify empty-string fallback: name → all dashes → trimmed → '' → sha1 fallback
        self::bootKernel();
        $repo = static::getContainer()->get(RegionRepository::class);

        $id = $repo->findOrCreateRegion('!!! --- !!!', 49.0, 3.0);

        self::assertGreaterThan(0, $id);
        $row = $repo->findRegionRowById($id);
        self::assertNotNull($row);
        self::assertStringStartsWith('region-', (string) $row['slug']);
    }

    // ── LocationRepository ────────────────────────────────────────────────────

    public function testLocationFindLatLngByIdsEmptyReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        self::assertSame([], $repo->findLatLngByIds([]));
    }

    public function testLocationFindLatLngByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        self::assertNull($repo->findLatLngById(999999999));
    }

    public function testLocationIdsWithStoredPhotoEmptyReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        self::assertSame([], $repo->idsWithStoredPhoto([]));
    }

    public function testLocationFindStoredPhotoByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        self::assertNull($repo->findStoredPhotoById(999999999));
    }

    public function testLocationFindLocationByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(LocationRepository::class);

        self::assertNull($repo->findLocationById(999999999));
    }

    // ── FriendRepository ──────────────────────────────────────────────────────

    public function testFriendOrderedPairSortsAscending(): void
    {
        self::assertSame([3, 7], FriendRepository::orderedPair(7, 3));
        self::assertSame([3, 7], FriendRepository::orderedPair(3, 7));
    }

    public function testFriendOrderedPairEqualIds(): void
    {
        self::assertSame([5, 5], FriendRepository::orderedPair(5, 5));
    }

    public function testFriendAreFriendsNonexistentUsersReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        self::assertFalse($repo->areFriends(999998999, 999999999));
    }

    public function testFriendListFriendsForNonexistentUserReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        self::assertSame([], $repo->listFriendsForUser(999999999));
    }

    public function testFriendSearchCandidatesEmptyQueryReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        self::assertSame([], $repo->searchCandidates(1, ''));
    }

    public function testFriendSuggestCandidatesReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        $rows = $repo->suggestCandidates(999999999, 5);

        self::assertIsArray($rows);
    }

    // ── FriendInvitationRepository ────────────────────────────────────────────

    public function testInvitationCountIncomingPendingForNonexistentUserReturnsZero(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertSame(0, $repo->countIncomingPending(999999999));
    }

    public function testInvitationHasPendingBetweenNonexistentUsersReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertFalse($repo->hasPendingBetween(999998999, 999999999));
    }

    public function testInvitationFindPendingBetweenNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertNull($repo->findPendingBetween(999998999, 999999999));
    }

    public function testInvitationSendToSelfThrowsInvalidArgument(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $repo->sendInvitation(42, 42);
    }

    public function testInvitationListIncomingForNonexistentUserReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertSame([], $repo->listIncoming(999999999));
    }

    public function testInvitationListOutgoingForNonexistentUserReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertSame([], $repo->listOutgoing(999999999));
    }

    public function testInvitationAcceptNonexistentThrowsInvalidArgument(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $repo->acceptInvitation(999999999, 1);
    }

    public function testInvitationCancelNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendInvitationRepository::class);

        self::assertFalse($repo->cancelInvitation(999999999, 1));
    }

    // ── FriendRepository – write paths ────────────────────────────────────────

    public function testFriendCreateAndRemoveFriendshipWithRealUsers(): void
    {
        self::bootKernel();
        $friendRepo = static::getContainer()->get(FriendRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(2);
        if (count($users) < 2) {
            self::markTestIncomplete('Need at least 2 users in DB.');
        }

        $idA = (int) $users[0]->getId();
        $idB = (int) $users[1]->getId();

        if ($friendRepo->areFriends($idA, $idB)) {
            // Clean up pre-existing friendship so we can test create
            $friendRepo->removeFriendship($idA, $idB);
        }

        $friendRepo->createFriendship($idA, $idB);
        self::assertTrue($friendRepo->areFriends($idA, $idB));

        $removed = $friendRepo->removeFriendship($idA, $idB);
        self::assertTrue($removed);
        self::assertFalse($friendRepo->areFriends($idA, $idB));
    }

    public function testFriendRemoveNonexistentFriendshipReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        self::assertFalse($repo->removeFriendship(999998999, 999999999));
    }

    public function testFriendCreateFriendshipWithSelfThrowsInvalidArgument(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $repo->createFriendship(42, 42);
    }

    public function testFriendSearchCandidatesWithQueryReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(FriendRepository::class);
        $userRepo = static::getContainer()->get(UserRepository::class);

        $users = $userRepo->topByTotalScore(1);
        if ($users === []) {
            self::markTestIncomplete('No users in DB.');
        }

        $userId = (int) $users[0]->getId();
        $results = $repo->searchCandidates($userId, 'a');

        self::assertIsArray($results);
    }

    // ── GameResultRepository ──────────────────────────────────────────────────

    public function testGameResultLeaderboardRowsDailyReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        $rows = $repo->leaderboardRows('daily', null);

        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('best_score', $rows[0]);
            self::assertArrayHasKey('name', $rows[0]);
        }
    }

    public function testGameResultLeaderboardRowsForNonexistentChallengeReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        self::assertSame([], $repo->leaderboardRows('challenge', 999999999));
    }

    public function testGameResultPreviousBestForUnknownUserReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(GameResultRepository::class);

        $result = $repo->previousBestTotalScore('daily', null, 999999999);

        self::assertNull($result);
    }

    // ── ChallengeRepository ───────────────────────────────────────────────────

    public function testChallengeCountLocationsForNonexistentSetReturnsZero(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertSame(0, $repo->countLocations(999999999));
    }

    public function testChallengeListChallengeSetsReturnsArray(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        $rows = $repo->listChallengeSets([], null);

        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('id', $rows[0]);
            self::assertArrayHasKey('title', $rows[0]);
            self::assertArrayHasKey('play_count', $rows[0]);
        }
    }

    public function testChallengeListMineWithUnknownUserReturnsEmpty(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertSame([], $repo->listMine(999999999));
    }

    public function testChallengeIsOwnedByUserNonexistentReturnsFalse(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertFalse($repo->isOwnedByUser(999999999, 1));
    }

    public function testChallengeFindChallengeSetNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertNull($repo->findChallengeSet(999999999, null));
    }

    public function testChallengeFindRandomLocationForNonexistentSetReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(ChallengeRepository::class);

        self::assertNull($repo->findRandomLocationForSet(999999999));
    }

    // ── DuelRepository ────────────────────────────────────────────────────────

    public function testDuelGetByIdNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelRepository::class);

        self::assertNull($repo->getDuelById(999999999));
    }

    public function testDuelAddDuelCreatesAndReturnsEntity(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelRepository::class);

        $duel = $repo->addDuel(1, 2);

        self::assertNotNull($duel->getId());
        self::assertSame(1, $duel->getP1());
        self::assertSame(2, $duel->getP2());
    }

    public function testDuelSetScoreUpdatesPlayerScore(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelRepository::class);

        $duel = $repo->addDuel(10, 20);
        $id = $duel->getId();
        $updated = $repo->setScore($id, 4500, true);

        self::assertSame(4500, $updated->getP1Score());
        self::assertTrue($updated->isP1Finish());
    }

    // ── DuelQueueRepository ───────────────────────────────────────────────────

    public function testDuelQueueGetLatestEntryNonexistentReturnsNull(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        self::assertNull($repo->getLatestUserEntry(999999999));
    }

    public function testDuelQueueAddEntryCreatesEntity(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $entry = $repo->addQueueEntry(999999999);

        self::assertNotNull($entry->getId());
        self::assertSame(999999999, $entry->getUserId());
        self::assertNull($entry->getMatchedWith());

        // Clean up
        $repo->removeOldUserEntries(999999999);
    }

    public function testDuelQueueMatchPlayerReturnsNullOrEntry(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(DuelQueueRepository::class);

        $result = $repo->matchPlayer();

        // Could be null (no unmatched entries) or a DuelQueueEntry
        self::assertTrue($result === null || $result instanceof \App\Entity\DuelQueueEntry);
    }
}
