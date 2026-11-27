<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ChallengeComment;
use App\Entity\ChallengeLike;
use App\Entity\ChallengeSet;
use App\Entity\ChallengeSetLocation;
use App\Entity\Duel;
use App\Entity\DuelQueueEntry;
use App\Entity\FriendInvitation;
use App\Entity\Friendship;
use App\Entity\GameResult;
use App\Entity\GameResultRound;
use App\Entity\Location;
use App\Entity\Region;
use App\Entity\User;
use App\Geo\GeoPoint;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase
{
    // -------------------------------------------------------------------------
    // User
    // -------------------------------------------------------------------------

    public function testUserDefaultValues(): void
    {
        $user = new User();

        self::assertNull($user->getId());
        self::assertSame('', $user->getName());
        self::assertSame('', $user->getHashpass());
        self::assertSame(0, $user->getLeuvenScore());
        self::assertSame(0, $user->getChallengeScore());
        self::assertSame(0, $user->getDuelScore());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testUserSettersReturnStatic(): void
    {
        $user = new User();

        self::assertSame($user, $user->setName('Alice'));
        self::assertSame($user, $user->setHashpass('hash'));
        self::assertSame($user, $user->setLeuvenScore(500));
        self::assertSame($user, $user->setChallengeScore(100));
        self::assertSame($user, $user->setDuelScore(50));
    }

    public function testUserNameAndHashpass(): void
    {
        $user = (new User())->setName('Bob')->setHashpass('secret');

        self::assertSame('Bob', $user->getName());
        self::assertSame('secret', $user->getHashpass());
    }

    public function testUserScores(): void
    {
        $user = (new User())
            ->setLeuvenScore(999)
            ->setChallengeScore(500)
            ->setDuelScore(250);

        self::assertSame(999, $user->getLeuvenScore());
        self::assertSame(500, $user->getChallengeScore());
        self::assertSame(250, $user->getDuelScore());
    }

    public function testUserRolesAlwaysIncludeRoleUser(): void
    {
        $user = new User();
        $user->setRoles([]);

        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testUserRolesAreUppercased(): void
    {
        $user = (new User())->setRoles(['role_admin']);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testUserRolesDeduplicated(): void
    {
        $user = (new User())->setRoles(['ROLE_USER', 'ROLE_USER', 'ROLE_ADMIN']);

        self::assertCount(2, $user->getRoles());
    }

    public function testUserRolesStripsNonStrings(): void
    {
        $user = (new User())->setRoles([123, null, 'ROLE_ADMIN']);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testUserIsAdmin(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);

        self::assertTrue($user->isAdmin());
    }

    public function testUserIsNotAdmin(): void
    {
        $user = (new User())->setRoles(['ROLE_USER']);

        self::assertFalse($user->isAdmin());
    }

    // -------------------------------------------------------------------------
    // Region
    // -------------------------------------------------------------------------

    public function testRegionDefaultValues(): void
    {
        $region = new Region();

        self::assertNull($region->getId());
        self::assertSame('', $region->getName());
        self::assertSame('', $region->getSlug());
        self::assertSame(0.0, $region->getCenterLat());
        self::assertSame(0.0, $region->getCenterLng());
        self::assertSame(0.0, $region->getSwLat());
        self::assertSame(0.0, $region->getSwLng());
        self::assertSame(0.0, $region->getNeLat());
        self::assertSame(0.0, $region->getNeLng());
    }

    public function testRegionSettersReturnStatic(): void
    {
        $region = new Region();

        self::assertSame($region, $region->setName('Leuven'));
        self::assertSame($region, $region->setSlug('leuven'));
        self::assertSame($region, $region->setCenterLat(50.88));
        self::assertSame($region, $region->setCenterLng(4.70));
        self::assertSame($region, $region->setSwLat(50.85));
        self::assertSame($region, $region->setSwLng(4.65));
        self::assertSame($region, $region->setNeLat(50.91));
        self::assertSame($region, $region->setNeLng(4.75));
    }

    public function testRegionToListRow(): void
    {
        $region = (new Region())
            ->setName('Leuven')
            ->setSlug('leuven')
            ->setCenterLat(50.88)
            ->setCenterLng(4.70)
            ->setSwLat(50.85)
            ->setSwLng(4.65)
            ->setNeLat(50.91)
            ->setNeLng(4.75);

        $row = $region->toListRow();

        self::assertSame('Leuven', $row['name']);
        self::assertSame('leuven', $row['slug']);
        self::assertSame(50.88, $row['center_lat']);
        self::assertSame(4.70, $row['center_lng']);
        self::assertSame(50.85, $row['sw_lat']);
        self::assertSame(4.65, $row['sw_lng']);
        self::assertSame(50.91, $row['ne_lat']);
        self::assertSame(4.75, $row['ne_lng']);
        self::assertNull($row['id']);
    }

    // -------------------------------------------------------------------------
    // Location
    // -------------------------------------------------------------------------

    public function testLocationDefaultValues(): void
    {
        $loc = new Location();

        self::assertNull($loc->getId());
        self::assertNull($loc->getCoordinates());
        self::assertSame('', $loc->getPhotoSource());
        self::assertNull($loc->getPhotoData());
        self::assertNull($loc->getPhotoMimeType());
    }

    public function testLocationSettersReturnStatic(): void
    {
        $loc = new Location();
        $point = new GeoPoint(50.88, 4.70);

        self::assertSame($loc, $loc->setCoordinates($point));
        self::assertSame($loc, $loc->setPhotoSource('photo.jpg'));
        self::assertSame($loc, $loc->setPhotoData('binary'));
        self::assertSame($loc, $loc->setPhotoMimeType('image/jpeg'));
    }

    public function testLocationCoordinates(): void
    {
        $point = new GeoPoint(50.88, 4.70);
        $loc = (new Location())->setCoordinates($point);

        self::assertSame($point, $loc->getCoordinates());
        self::assertSame(50.88, $loc->getCoordinates()->lat);
        self::assertSame(4.70, $loc->getCoordinates()->lng);
    }

    public function testLocationPhoto(): void
    {
        $loc = (new Location())
            ->setPhotoSource('img.png')
            ->setPhotoData('data')
            ->setPhotoMimeType('image/png');

        self::assertSame('img.png', $loc->getPhotoSource());
        self::assertSame('data', $loc->getPhotoData());
        self::assertSame('image/png', $loc->getPhotoMimeType());
    }

    // -------------------------------------------------------------------------
    // ChallengeSet
    // -------------------------------------------------------------------------

    public function testChallengeSetDefaultValues(): void
    {
        $set = new ChallengeSet();

        self::assertNull($set->getId());
        self::assertSame('', $set->getTitle());
        self::assertNull($set->getDescription());
        self::assertSame('', $set->getAuthorName());
        self::assertNull($set->getAuthorUserId());
        self::assertSame(0, $set->getPlayCount());
        self::assertSame(0, $set->getLikeCount());
        self::assertSame(0, $set->getCommentCount());
        self::assertTrue($set->isPublished());
        self::assertCount(0, $set->getSetLocations());
    }

    public function testChallengeSetSettersReturnStatic(): void
    {
        $set = new ChallengeSet();
        $region = new Region();

        self::assertSame($set, $set->setTitle('My Set'));
        self::assertSame($set, $set->setDescription('desc'));
        self::assertSame($set, $set->setRegion($region));
        self::assertSame($set, $set->setAuthorName('Alice'));
        self::assertSame($set, $set->setAuthorUserId(1));
        self::assertSame($set, $set->setLikeCount(5));
        self::assertSame($set, $set->setPublished(false));
    }

    public function testChallengeSetTitleAndDescription(): void
    {
        $set = (new ChallengeSet())
            ->setTitle('Leuven Walk')
            ->setDescription('A tour of Leuven');

        self::assertSame('Leuven Walk', $set->getTitle());
        self::assertSame('A tour of Leuven', $set->getDescription());
    }

    public function testChallengeSetNullDescription(): void
    {
        $set = (new ChallengeSet())->setDescription(null);

        self::assertNull($set->getDescription());
    }

    public function testChallengeSetAuthor(): void
    {
        $set = (new ChallengeSet())
            ->setAuthorName('Bob')
            ->setAuthorUserId(42);

        self::assertSame('Bob', $set->getAuthorName());
        self::assertSame(42, $set->getAuthorUserId());
    }

    public function testChallengeSetIncrementPlayCount(): void
    {
        $set = new ChallengeSet();
        $set->incrementPlayCount();
        $set->incrementPlayCount();

        self::assertSame(2, $set->getPlayCount());
    }

    public function testChallengeSetLikeCount(): void
    {
        $set = (new ChallengeSet())->setLikeCount(7);

        self::assertSame(7, $set->getLikeCount());
    }

    public function testChallengeSetIncrementCommentCount(): void
    {
        $set = new ChallengeSet();
        $set->incrementCommentCount();
        $set->incrementCommentCount();

        self::assertSame(2, $set->getCommentCount());
    }

    public function testChallengeSetDecrementCommentCount(): void
    {
        $set = new ChallengeSet();
        $set->incrementCommentCount();
        $set->decrementCommentCount();

        self::assertSame(0, $set->getCommentCount());
    }

    public function testChallengeSetDecrementCommentCountDoesNotGoBelowZero(): void
    {
        $set = new ChallengeSet();
        $set->decrementCommentCount();

        self::assertSame(0, $set->getCommentCount());
    }

    public function testChallengeSetPublishedFlag(): void
    {
        $set = (new ChallengeSet())->setPublished(false);

        self::assertFalse($set->isPublished());
    }

    public function testChallengeSetRegion(): void
    {
        $region = (new Region())->setName('Brussels');
        $set = (new ChallengeSet())->setRegion($region);

        self::assertSame($region, $set->getRegion());
    }

    public function testChallengeSetAddSetLocation(): void
    {
        $set = new ChallengeSet();
        $loc = new Location();
        $setLoc = (new ChallengeSetLocation())->setLocation($loc);

        $set->addSetLocation($setLoc);

        self::assertCount(1, $set->getSetLocations());
        self::assertSame($set, $setLoc->getChallengeSet());
    }

    public function testChallengeSetAddSetLocationNoDuplicates(): void
    {
        $set = new ChallengeSet();
        $loc = new Location();
        $setLoc = (new ChallengeSetLocation())->setLocation($loc);

        $set->addSetLocation($setLoc);
        $set->addSetLocation($setLoc);

        self::assertCount(1, $set->getSetLocations());
    }

    // -------------------------------------------------------------------------
    // ChallengeSetLocation
    // -------------------------------------------------------------------------

    public function testChallengeSetLocationSettersReturnStatic(): void
    {
        $setLoc = new ChallengeSetLocation();
        $set = new ChallengeSet();
        $loc = new Location();

        self::assertSame($setLoc, $setLoc->setChallengeSet($set));
        self::assertSame($setLoc, $setLoc->setLocation($loc));
        self::assertSame($setLoc, $setLoc->setPosition(3));
    }

    public function testChallengeSetLocationValues(): void
    {
        $set = new ChallengeSet();
        $loc = new Location();
        $setLoc = (new ChallengeSetLocation())
            ->setChallengeSet($set)
            ->setLocation($loc)
            ->setPosition(5);

        self::assertSame($set, $setLoc->getChallengeSet());
        self::assertSame($loc, $setLoc->getLocation());
        self::assertSame(5, $setLoc->getPosition());
    }

    // -------------------------------------------------------------------------
    // ChallengeComment
    // -------------------------------------------------------------------------

    public function testChallengeCommentDefaultValues(): void
    {
        $comment = new ChallengeComment();

        self::assertNull($comment->getId());
        self::assertSame(0, $comment->getUserId());
        self::assertSame('', $comment->getAuthorName());
        self::assertSame('', $comment->getBody());
    }

    public function testChallengeCommentSettersReturnStatic(): void
    {
        $comment = new ChallengeComment();
        $set = new ChallengeSet();

        self::assertSame($comment, $comment->setChallengeSet($set));
        self::assertSame($comment, $comment->setUserId(1));
        self::assertSame($comment, $comment->setAuthorName('Alice'));
        self::assertSame($comment, $comment->setBody('Great!'));
    }

    public function testChallengeCommentValues(): void
    {
        $set = new ChallengeSet();
        $comment = (new ChallengeComment())
            ->setChallengeSet($set)
            ->setUserId(12)
            ->setAuthorName('Bob')
            ->setBody('Nice challenge!');

        self::assertSame($set, $comment->getChallengeSet());
        self::assertSame(12, $comment->getUserId());
        self::assertSame('Bob', $comment->getAuthorName());
        self::assertSame('Nice challenge!', $comment->getBody());
    }

    // -------------------------------------------------------------------------
    // ChallengeLike
    // -------------------------------------------------------------------------

    public function testChallengeLikeDefaultValues(): void
    {
        $like = new ChallengeLike();

        self::assertNull($like->getId());
        self::assertSame(0, $like->getUserId());
    }

    public function testChallengeLikeSettersReturnStatic(): void
    {
        $like = new ChallengeLike();
        $set = new ChallengeSet();

        self::assertSame($like, $like->setChallengeSet($set));
        self::assertSame($like, $like->setUserId(3));
    }

    public function testChallengeLikeValues(): void
    {
        $set = new ChallengeSet();
        $like = (new ChallengeLike())
            ->setChallengeSet($set)
            ->setUserId(9);

        self::assertSame($set, $like->getChallengeSet());
        self::assertSame(9, $like->getUserId());
    }

    // -------------------------------------------------------------------------
    // GameResult
    // -------------------------------------------------------------------------

    public function testGameResultConstants(): void
    {
        self::assertSame('leuven_challenge', GameResult::MODE_LEUVEN);
        self::assertSame('world_challenge', GameResult::MODE_WORLD);
        self::assertSame('duel_mode', GameResult::MODE_DUEL);
    }

    public function testGameResultDefaultValues(): void
    {
        $result = new GameResult();

        self::assertNull($result->getId());
        self::assertSame('', $result->getMode());
        self::assertNull($result->getChallengeSetId());
        self::assertSame(0, $result->getUserId());
        self::assertSame(0, $result->getTotalScore());
        self::assertSame(0, $result->getMaxScore());
        self::assertSame(0, $result->getRoundCount());
        self::assertCount(0, $result->getRounds());
    }

    public function testGameResultSettersReturnStatic(): void
    {
        $result = new GameResult();

        self::assertSame($result, $result->setMode(GameResult::MODE_LEUVEN));
        self::assertSame($result, $result->setChallengeSetId(1));
        self::assertSame($result, $result->setUserId(2));
        self::assertSame($result, $result->setTotalScore(3000));
        self::assertSame($result, $result->setRoundCount(5));
    }

    public function testGameResultValues(): void
    {
        $result = (new GameResult())
            ->setMode(GameResult::MODE_WORLD)
            ->setChallengeSetId(7)
            ->setUserId(42)
            ->setTotalScore(4500)
            ->setRoundCount(5);

        self::assertSame(GameResult::MODE_WORLD, $result->getMode());
        self::assertSame(7, $result->getChallengeSetId());
        self::assertSame(42, $result->getUserId());
        self::assertSame(4500, $result->getTotalScore());
        self::assertSame(5000, $result->getMaxScore());
        self::assertSame(5, $result->getRoundCount());
    }

    public function testGameResultAddRound(): void
    {
        $result = new GameResult();
        $round = (new GameResultRound())->setRoundNumber(1)->setScore(800);

        $result->addRound($round);

        self::assertCount(1, $result->getRounds());
        self::assertSame($result, $round->getGameResult());
    }

    public function testGameResultAddRoundNoDuplicates(): void
    {
        $result = new GameResult();
        $round = new GameResultRound();

        $result->addRound($round);
        $result->addRound($round);

        self::assertCount(1, $result->getRounds());
    }

    // -------------------------------------------------------------------------
    // GameResultRound
    // -------------------------------------------------------------------------

    public function testGameResultRoundDefaultValues(): void
    {
        $round = new GameResultRound();

        self::assertNull($round->getId());
        self::assertNull($round->getGameResult());
        self::assertNull($round->getLocationId());
        self::assertSame(0, $round->getRoundNumber());
        self::assertNull($round->getDistanceMeters());
        self::assertSame(0, $round->getScore());
    }

    public function testGameResultRoundSettersReturnStatic(): void
    {
        $round = new GameResultRound();
        $result = new GameResult();

        self::assertSame($round, $round->setGameResult($result));
        self::assertSame($round, $round->setLocationId(5));
        self::assertSame($round, $round->setRoundNumber(3));
        self::assertSame($round, $round->setDistanceMeters(150.5));
        self::assertSame($round, $round->setScore(900));
    }

    public function testGameResultRoundValues(): void
    {
        $result = new GameResult();
        $round = (new GameResultRound())
            ->setGameResult($result)
            ->setLocationId(10)
            ->setRoundNumber(2)
            ->setDistanceMeters(200.0)
            ->setScore(750);

        self::assertSame($result, $round->getGameResult());
        self::assertSame(10, $round->getLocationId());
        self::assertSame(2, $round->getRoundNumber());
        self::assertSame(200.0, $round->getDistanceMeters());
        self::assertSame(750, $round->getScore());
    }

    public function testGameResultRoundNullableFields(): void
    {
        $round = (new GameResultRound())
            ->setGameResult(null)
            ->setLocationId(null)
            ->setDistanceMeters(null);

        self::assertNull($round->getGameResult());
        self::assertNull($round->getLocationId());
        self::assertNull($round->getDistanceMeters());
    }

    // -------------------------------------------------------------------------
    // Duel
    // -------------------------------------------------------------------------

    public function testDuelDefaultValues(): void
    {
        $duel = new Duel();

        self::assertSame('NOT STARTED', $duel->getStatus());
        self::assertNull($duel->getLocation());
        self::assertNull($duel->getP1());
        self::assertNull($duel->getP2());
        self::assertSame(0, $duel->getP1Score());
        self::assertSame(0, $duel->getP2Score());
        self::assertFalse($duel->isP1Finish());
        self::assertFalse($duel->isP2Finish());
        self::assertFalse($duel->areBothFinish());
    }

    public function testDuelSettersReturnStatic(): void
    {
        $duel = new Duel();

        self::assertSame($duel, $duel->setP1(1));
        self::assertSame($duel, $duel->setP2(2));
    }

    public function testDuelPlayers(): void
    {
        $duel = (new Duel())->setP1(10)->setP2(20);

        self::assertSame(10, $duel->getP1());
        self::assertSame(20, $duel->getP2());
    }

    public function testDuelLocation(): void
    {
        $duel = new Duel();
        $duel->setLocation(99);

        self::assertSame(99, $duel->getLocation());
    }

    public function testDuelStatus(): void
    {
        $duel = new Duel();
        $duel->setStatus('ENDED');

        self::assertSame('ENDED', $duel->getStatus());
    }

    public function testDuelScores(): void
    {
        $duel = new Duel();
        $duel->setP1Score(800);
        $duel->setP2Score(600);

        self::assertSame(800, $duel->getP1Score());
        self::assertSame(600, $duel->getP2Score());
    }

    public function testDuelAreBothFinishWhenOnlyP1Done(): void
    {
        $duel = new Duel();
        $duel->setP1Finish(true);

        self::assertFalse($duel->areBothFinish());
    }

    public function testDuelAreBothFinishWhenBothDone(): void
    {
        $duel = new Duel();
        $duel->setP1Finish(true);
        $duel->setP2Finish(true);

        self::assertTrue($duel->areBothFinish());
        self::assertTrue($duel->isP1Finish());
        self::assertTrue($duel->isP2Finish());
    }

    // -------------------------------------------------------------------------
    // DuelQueueEntry
    // -------------------------------------------------------------------------

    public function testDuelQueueEntryDefaultValues(): void
    {
        $entry = new DuelQueueEntry();

        self::assertNull($entry->getMatchedWith());
        self::assertNull($entry->getResultMatch());
    }

    public function testDuelQueueEntrySetters(): void
    {
        $entry = new DuelQueueEntry();
        $entry->setUserId(5);
        $entry->setMatched(true);
        $entry->setMatchedWith(7);
        $entry->setResultMatch(42);

        self::assertSame(5, $entry->getUserId());
        self::assertSame(7, $entry->getMatchedWith());
        self::assertSame(42, $entry->getResultMatch());
    }

    public function testDuelQueueEntrySetterReturnStatic(): void
    {
        $entry = new DuelQueueEntry();

        self::assertSame($entry, $entry->setUserId(1));
    }

    // -------------------------------------------------------------------------
    // FriendInvitation
    // -------------------------------------------------------------------------

    public function testFriendInvitationDefaultValues(): void
    {
        $inv = new FriendInvitation();

        self::assertNull($inv->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $inv->getCreatedAt());
    }

    public function testFriendInvitationSettersReturnStatic(): void
    {
        $inv = new FriendInvitation();
        $user = new User();

        self::assertSame($inv, $inv->setFromUser($user));
        self::assertSame($inv, $inv->setToUser($user));
    }

    public function testFriendInvitationUsers(): void
    {
        $from = (new User())->setName('Alice');
        $to = (new User())->setName('Bob');
        $inv = (new FriendInvitation())
            ->setFromUser($from)
            ->setToUser($to);

        self::assertSame($from, $inv->getFromUser());
        self::assertSame($to, $inv->getToUser());
        self::assertSame('Alice', $inv->getFromUser()->getName());
        self::assertSame('Bob', $inv->getToUser()->getName());
    }

    // -------------------------------------------------------------------------
    // Friendship
    // -------------------------------------------------------------------------

    public function testFriendshipDefaultValues(): void
    {
        $friendship = new Friendship();

        self::assertNull($friendship->getId());
    }

    public function testFriendshipSettersReturnStatic(): void
    {
        $friendship = new Friendship();
        $user = new User();

        self::assertSame($friendship, $friendship->setUser1($user));
        self::assertSame($friendship, $friendship->setUser2($user));
    }

    public function testFriendshipUsers(): void
    {
        $u1 = (new User())->setName('Alice');
        $u2 = (new User())->setName('Bob');
        $friendship = (new Friendship())
            ->setUser1($u1)
            ->setUser2($u2);

        self::assertSame($u1, $friendship->getUser1());
        self::assertSame($u2, $friendship->getUser2());
    }
}
