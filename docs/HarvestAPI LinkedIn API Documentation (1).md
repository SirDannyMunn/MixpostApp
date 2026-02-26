# HarvestAPI LinkedIn API Documentation

**Note:** All endpoints require an X-API-Key HTTP header for authentication[\[1\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=). The base URL for all endpoints is https://api.harvest-api.com. Below is a complete reference of LinkedIn-related API endpoints, grouped by category, with their input parameters and output data structures.

## LinkedIn Profile

### Get Profile

Retrieves a LinkedIn user’s full profile. Requires at least one identifier (profile URL, public identifier, or profile ID) to specify the target profile[\[2\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Get%20the%20LinkedIn%20profile%20of,one%20of%20the%20query%20parameters)[\[3\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=url). Optional flags allow retrieving a main-summary-only version of the profile or performing email discovery.

* **Endpoint:** GET /linkedin/profile

* **Query Parameters:**

* url (string, URL of the LinkedIn profile – *optional*)[\[4\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=url)

* publicIdentifier (string, LinkedIn profile handle – *optional*)[\[5\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=publicIdentifier)

* profileId (string, LinkedIn profile ID – *optional*)[\[6\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Public%20identifier%20of%20the%20LinkedIn,optional)

* main (string, set to "true" to fetch only the main profile sections – returns limited fields for lower credit cost[\[7\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=main))

* findEmail (string, include to perform email lookup for the profile (SMTP-verified); higher credit cost[\[8\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=string)[\[9\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Include%20this%20parameter%20to%20find,This%20version%20charges%20more%20credits))

* skipSmtp (string, use with findEmail to skip SMTP verification (generate all possible emails with minimal validation)[\[10\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=skipSmtp))

* includeAboutProfile (string, include to scrape the **“About this profile”** pop-up information[\[11\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=includeAboutProfile))

* **Response:** On success, returns a JSON object with the profile details in an element field, plus status and query echo. The profile data includes name, headline, photo, bio, profile URL, and detailed sub-sections like location, current positions, work experience, education, certifications, recommendations, skills, languages, projects, publications, featured content, and flags (openToWork, hiring, verified, etc.)[\[12\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=%7B%20,string)[\[13\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=,). An example response structure is shown below:

json { "element": { "publicIdentifier": "\<string\>", "id": "\<string\>", "firstName": "\<string\>", "lastName": "\<string\>", "headline": "\<string\>", "about": "\<string\>", "linkedinUrl": "\<string\>", "photo": "\<string\>", "registeredAt": "2023-11-07T05:31:56Z", "topSkills": "\<string\>", "connectionsCount": 123, "followerCount": 123, "openToWork": true, "hiring": true, "location": { "linkedinText": "\<string\>", "countryCode": "\<string\>", "parsed": { "text": "\<string\>", "countryCode": "\<string\>", "regionCode": "\<string\>", "country": "\<string\>", "countryFull": "\<string\>", "state": "\<string\>", "city": "\<string\>" } }, "currentPosition": \[ { "companyName": "\<string\>" } \], "experience": \[ { "companyName": "\<string\>", "duration": "\<string\>", "position": "\<string\>", "location": "\<string\>", "companyLink": "\<string\>", "description": "\<string\>", "startDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" }, "endDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" }, "employmentType": "\<string\>" } \], "education": \[ { "title": "\<string\>", "link": "\<string\>", "degree": "\<string\>", "startDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" }, "endDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" } } \], "certifications": \[ { "title": "\<string\>", "issuedAt": "\<string\>", "issuedBy": "\<string\>", "issuedByLink": "\<string\>" } \], "receivedRecommendations": \[ { "givenBy": "\<string\>", "givenAt": "\<string\>", "givenByLink": "\<string\>", "description": "\<string\>" } \], "skills": \[ { "name": "\<string\>" } \], "languages": \[ { "language": "\<string\>", "proficiency": "\<string\>" } \], "projects": \[ { "title": "\<string\>", "description": "\<string\>", "startDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" }, "endDate": { "month": "\<string\>", "year": 123, "text": "\<string\>" } } \], "publications": \[ { "title": "\<string\>", "publishedAt": "\<string\>", "description": "\<string\>", "link": "\<string\>" } \], "featured": { "images": \[ "\<string\>" \], "link": "\<string\>", "title": "\<string\>", "subtitle": "\<string\>" }, "verified": true }, "status": "\<string\>", "error": "\<string\>", "query": { "url": "\<string\>", "publicIdentifier": "\<string\>", "profileId": "\<string\>" } }[\[14\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=%7B%20,string)[\[15\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=,)

### Search Leads

Searches for LinkedIn profiles using LinkedIn’s **Lead Search** (Sales Navigator) functionality. This endpoint uses a different LinkedIn search mechanism to avoid the limitations of the standard search (e.g. fewer “LinkedIn Member” anonymized results)[\[16\]](https://docs.harvest-api.com/guides/profile-search#:~:text=Lead%20search%20scraper). It is designed for large-scale extraction and can retrieve a very high volume of profiles quickly[\[17\]](https://docs.harvest-api.com/guides/profile-search#:~:text=The%20Lead%20search%20endpoint%20scrapes,scale%20scraping%20volumes). *(This corresponds to the Sales Navigator lead search; it returns profiles relevant to specified criteria.)*

* **Endpoint:** GET /linkedin/lead-search

* **Query Parameters:** Supports parameters similar to the basic profile search (see **Search Profiles** below) such as name or keywords, location filters, company filters, etc., but tuned to the Sales Navigator leads search. (The exact filter fields mirror those available in Sales Navigator, enabling more comprehensive people searches.)

* **Response:** Returns a paginated list of matching profiles. The JSON structure is similar to **Search Profiles** (an array of profile summary objects in elements, plus pagination info, status, error, and query echo).

### Search Profiles

Searches LinkedIn public profiles by name and other criteria (the standard people search). Supports filtering by company, location, etc.[\[18\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Search%20Profiles). This is the basic people search scraper (note that without a LinkedIn account, some results may appear as “LinkedIn Member” with limited info)[\[19\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=This%20is%20basic%20profile%20search,this%20information%20before%20using%20it).

* **Endpoint:** GET /linkedin/profile-search

* **Query Parameters:**

* search (string, the name or keywords to search profiles by – *required*)[\[20\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,)[\[21\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=const%20params%20%3D%20new%20URLSearchParams%28,key%3E%27)

* currentCompany (string, filter by current company – accept company URL or ID, single or comma-separated multiple)[\[22\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=currentCompany)

* pastCompany (string, filter by past company – URLs or IDs, comma-separated)[\[23\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20company%20ID%20or,separated)

* school (string, filter by school – URLs or IDs, comma-separated)[\[24\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=school)

* firstName (string, filter by first name)[\[25\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=firstName)

* lastName (string, filter by last name)[\[26\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20first%20name)

* title (string, filter by current job title or position)[\[27\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20last%20name)

* location (string, filter by location name text)[\[28\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20title)

* geoId (string, filter by LinkedIn location GeoID; if provided, it overrides location text filter[\[29\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20location%20text))

* industryId (string, filter by industry ID(s), comma-separated)[\[30\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=industryId)

* keywordsCompany (string, filter by keywords in company names)[\[31\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20industry%20ID,separated)

* keywordsSchool (string, filter by keywords in school names)[\[32\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20keywords%20in%20company,name)

* page (integer, results page number for pagination, default 1\)[\[33\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=page)

* **Response:** Returns a JSON object with an array elements of profile summary objects and a pagination object. Each profile element includes fields such as publicIdentifier, id, name, position (headline), location (text), linkedinUrl, photo, and a hidden flag (true if profile is partially hidden)[\[34\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=%7B%20,)[\[35\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,123). Pagination info (total pages, total elements, page number, etc.) and the original query parameters are also included[\[36\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,string)[\[37\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,). For example:

json { "elements": \[ { "publicIdentifier": "\<string\>", "id": "\<string\>", "name": "\<string\>", "position": "\<string\>", "location": { "linkedinText": "\<string\>" }, "linkedinUrl": "\<string\>", "photo": "\<string\>", "hidden": true } \], "pagination": { "totalPages": 123, "totalElements": 123, "pageNumber": 123, "previousElements": 123, "pageSize": 123, "paginationToken": "\<string\>" }, "status": "\<string\>", "error": "\<string\>", "query": { "search": "\<string\>", "companyId": "\<string\>", "location": "\<string\>", "geoId": "\<string\>", "page": "\<string\>" } }[\[38\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=%7B%20,string)[\[39\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,%7D)

### Profile Posts

Retrieves all LinkedIn posts authored by a specific user profile. Use this endpoint to get a profile’s own posts, overcoming limitations of the general post search (which may return fewer results per profile)[\[40\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string)[\[41\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Filter%20posts%20by%20author%27s%20profile,posts%20endpoint).

* **Endpoint:** GET /linkedin/profile-posts

* **Query Parameters:**

* profile (string, LinkedIn profile URL of the person whose posts to fetch – optional if profileId is provided)

* profileId (string, LinkedIn profile ID – it’s faster to use the numeric ID – *optional*)[\[42\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=profileId)

* postedLimit (string, filter by recency of posts; e.g. '24h', 'week', 'month' – *optional*)

* page (integer, page number for pagination, default 1\)

* paginationToken (string, token for fetching next pages if provided in prior response – *used for page \> 1*)

* **Response:** Returns the profile’s posts in an elements array, with each element containing post content and metadata similar to the output of **Search Posts** (see the LinkedIn Post section). It includes fields like id, content (text content), media attachments (postImages, postVideo, or article if any), author info (the profile), timestamps (postedAgo), and engagement counts (likes, comments, etc.)[\[43\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7B%20,string)[\[44\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,true). Pagination info is included as well (note: when fetching all posts by profile, the response may include a paginationToken for retrieving additional posts beyond LinkedIn’s usual page limits)[\[45\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=postedLimit)[\[46\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=paginationToken).

### Profile Comments

Gets the comments made **by** a LinkedIn user (the profile’s recent activity in commenting on posts). It scrapes the profile’s “Recent Activity – Comments” page[\[47\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Get%20comments%20made%20by%20a,activity%2Fcomments).

* **Endpoint:** GET /linkedin/profile-comments

* **Query Parameters:**

* profile (string, LinkedIn profile URL of the user – optional if profileId is given)[\[48\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=profile)

* profileId (string, LinkedIn profile ID – faster if available – *optional*)[\[49\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=URL%20of%20the%20LinkedIn%20profile)

* postedLimit (string, filter comments by comment date; supports '24h', 'week', 'month' – *optional*)[\[50\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=postedLimit)

* page (integer, page number for pagination, default 1\)[\[51\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Filter%20posts%20by%20maximum%20posted,Supported%20values%3A%20%2724h%27%2C%20%27week%27%2C%20%27month)

* paginationToken (string, token for next-page retrieval, required for page \> 1\)[\[52\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Page%20number%20for%20pagination,is%201)

* **Response:** Returns comments in an elements list, where each element represents one comment made by the profile. Each comment object includes an id, the linkedinUrl of the comment, the comment text (commentary), the timestamp (createdAt and createdAtTimestamp), the ID of the post on which the comment was made (postId), and an actor sub-object with the profile’s own basic info (id, name, profile URL, etc., marked "author": false since the profile is the commenter, not the original post author)[\[53\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=%7B%20,29%22%2C%20%22commentary%22%3A%20%22Exciting)[\[54\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,Public%20Sector%20Projects). Engagement info for each comment (e.g. numComments replies, reactions counts per type in reactionTypeCounts) is also provided[\[55\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,)[\[56\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=%7B%20,123). Pagination info (totalPages, etc.) and status/error fields are included as usual[\[57\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,string)[\[58\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=).

**Example:** Comments by a profile, showing one comment’s structure:

json { "elements": \[ { "id": "7330012053861998592", "linkedinUrl": "https://www.linkedin.com/feed/update/urn:li:ugcPost:...commentUrn=urn%3Ali%3Acomment%3A(ugcPost:...,7330012053861998592)", "commentary": "Exciting ", "createdAt": "2025-05-18T23:30:58.680Z", "numComments": 0, "postId": "7329991434395160578", "actor": { "id": "ACoAABLGFg4BRMcDx84MmyU8X-Jqcw9wKCA1QxU", "name": "Harshavardhan G H", "linkedinUrl": "https://www.linkedin.com/in/harshavardhangh", "author": false, "position": "Business Analyst | Data-Driven Decision Maker | ...", "pictureUrl": "https://media.licdn.com/dms/image/...profile-displayphoto-shrink\_800\_800/0/1730799919024?...", "picture": { "url": "https://media.licdn.com/dms/image/...profile-displayphoto-shrink\_800\_800/0/1730799919024?...", "width": 800, "height": 800, "expiresAt": 1753920000000 } }, "createdAtTimestamp": 1747611058680, "pinned": false, "contributed": false, "edited": false, "numShares": null, "numImpressions": null, "reactionTypeCounts": \[ { "type": "LIKE", "count": 1 } \] } \], "pagination": { "totalPages": 123, "totalElements": 123, "pageNumber": 123, "previousElements": 123, "pageSize": 123, "paginationToken": "\<string\>" }, "status": "\<string\>", "error": "\<string\>" }[\[53\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=%7B%20,29%22%2C%20%22commentary%22%3A%20%22Exciting)[\[55\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,)

*(The example above is truncated for brevity.)* This endpoint effectively returns the same data visible on the profile’s “Comments” activity tab[\[47\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Get%20comments%20made%20by%20a,activity%2Fcomments)[\[59\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=const%20params%20%3D%20new%20URLSearchParams%28,key%3E%27).

### Profile Reactions

Retrieves the posts that a LinkedIn user has reacted to (the profile’s “Likes and Reactions” activity). This endpoint scrapes the profile’s “Recent Activity – Reactions” page[\[60\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20profile)[\[61\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=LinkedIn%20Profile).

* **Endpoint:** GET /linkedin/profile-reactions

* **Query Parameters:**

* profile (string, LinkedIn profile URL – optional if profileId is provided)[\[62\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=profile)

* profileId (string, LinkedIn profile ID – *optional*)[\[63\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=URL%20of%20the%20LinkedIn%20profile)

* page (integer, page number for pagination, default 1\)[\[64\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Profile%20ID%20of%20the%20LinkedIn,faster%20to%20search%20by%20ID)

* paginationToken (string, pagination token for next pages – required for page \> 1\)[\[65\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Page%20number%20for%20pagination,is%201)

* **Response:** Returns a list of reaction records in elements. Each element represents one reaction the user made, including: an id (URN of the reaction), the reactionType (e.g. LIKE, CELEBRATE, etc.), the postId of the content that was reacted to, and an actor object with the reacting user’s profile info[\[66\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=%7B%20,linkedinUrl)[\[67\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Xs0LPljAa2PsGpc8%2Curn%3Ali%3Aactivity%3A7330681775884533760%2C0%29,Mohite%20College%20of%20Arts%2C%20Science). Essentially, it lists the posts that the user reacted to, with minimal info about those posts. Pagination info and status/error are included similarly to other endpoints[\[68\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=)[\[69\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Post%20reactions%20response).

**Example element:**

json { "id": "urn:li:fsd\_reaction:(urn:li:fsd\_profile:ACoAAFsSba4...,urn:li:activity:7330681775884533760,0)", "reactionType": "LIKE", "postId": "7330681775884533760", "actor": { "id": "ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8", "name": "Om More", "linkedinUrl": "https://www.linkedin.com/in/ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8", "position": "Student at ...", "pictureUrl": "https://media.licdn.com/dms/image/.../0/1748166110665?...", "picture": { "url": "...", "width": 800, "height": 800, "expiresAt": 1753920000000 } } }[\[66\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=%7B%20,linkedinUrl)[\[70\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=,s%20hrink_800_800%2FB4EZcG0eZVHAAc)

Only top-level fields are shown above; the actual response wraps an array of such elements with pagination info[\[71\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=200)[\[72\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=elements).

## LinkedIn Company

### Get Company

Retrieves detailed information about a LinkedIn company page (organization profile). You can specify the company by URL or by its universal name. If a company name is provided via the search parameter, the API will return the most relevant company match[\[73\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=url)[\[74\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=search).

* **Endpoint:** GET /linkedin/company

* **Query Parameters:**

* url (string, URL of the LinkedIn company page – *optional*)[\[75\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=url)

* universalName (string, the company’s unique name/slug as in the URL – *optional*)[\[76\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=URL%20of%20the%20LinkedIn%20company,optional)

* search (string, company name keywords – if provided, the API performs a search and returns the top result matching the name[\[74\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=search))

*(At least one of the above should be provided. If multiple are provided, url or universalName will directly fetch that company; search is used to lookup by name.)*

* **Response:** On success, returns a JSON object with the company’s profile data in an element field. Key fields include the company’s id (LinkedIn internal ID), name, tagline, website, linkedinUrl, and logo image URL[\[77\]\[78\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string). The object also contains details like year founded (foundedOn date), employee count (employeeCount and a range), follower count, a textual description, headquarters address (headquarter and possibly a list of other locations), a list of specialities, industry sector(s), and media assets (arrays of logos and backgroundCovers)[\[79\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string)[\[80\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=%7D%2C%20,string)[\[81\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string). If available, the response may include Crunchbase funding data for the organization (crunchbaseFundingData) and a flag pageVerified[\[82\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,%5B)[\[83\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=%7D%2C%20,string). Standard status, error, and query fields accompany the result[\[84\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,)[\[85\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,%7D).

**Example (partial):**

json { "element": { "id": "\<string\>", "name": "\<string\>", "tagline": "\<string\>", "website": "\<string\>", "linkedinUrl": "\<string\>", "logo": "\<string\>", "foundedOn": { "month": "\<string\>", "year": 123, "day": "\<string\>" }, "employeeCount": 123, "employeeCountRange": { "start": 123, "end": 123 }, "followerCount": 123, "description": "\<string\>", "headquarter": { "geographicArea": "\<string\>", "city": "\<string\>", "country": "\<string\>", "postalCode": "\<string\>", "line1": "\<string\>", "line2": "\<string\>", "description": "\<string\>", "parsed": { "text": "\<string\>", "countryCode": "\<string\>", "regionCode": "\<string\>", "country": "\<string\>", "countryFull": "\<string\>", "state": "\<string\>", "city": "\<string\>" } }, "locations": \[ { "localizedName": "\<string\>", "headquarter": true, ... } \], "specialities": \[ "\<string\>" \], "industries": \[ "\<string\>" \], "logos": \[ { "url": "\<string\>", "width": 123, "height": 123, "expiresAt": 123 } \], "backgroundCovers": \[ { "url": "\<string\>", "width": 123, "height": 123, "expiresAt": 123 } \], "active": true, "jobSearchUrl": "\<string\>", "phone": "\<string\>", "crunchbaseFundingData": { ... }, "pageVerified": true }, "status": "\<string\>", "error": "\<string\>", "query": { "url": "\<string\>", "universalName": "\<string\>" } }[\[77\]\[86\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string)

### Search Companies

Retrieves a list of LinkedIn companies matching a search query. This endpoint allows searching organizations by name and filtering by location or size[\[87\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=Search%20Companies)[\[88\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=search).

* **Endpoint:** GET /linkedin/company-search

* **Query Parameters:**

* search (string, keywords to search in company names – *required*)[\[88\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=search)

* location (string, filter companies by location name text – *optional*)[\[89\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=Keywords%20to%20search%20for%20in,company%20names)

* geoId (string, filter by location using a LinkedIn GeoID; if provided, overrides the location text filter[\[90\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=geoId))

* companySize (string, filter by company size range; one value or multiple comma-separated. Supported values: 1-10, 11-50, 51-200, 201-500, 501-1000, 1001-5000, 5001-10000, 10001+[\[91\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=companySize))

* page (integer, page number for pagination, default 1\)[\[92\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=%2710001%2B%27)

* **Response:** Returns a JSON object containing an array of matching companies in elements, plus pagination info. Each company element includes fields such as universalName (company slug), id, name, industries (textual category), location (location text), followers (count as string), summary (short description), logo (image URL), and linkedinUrl[\[93\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=%7B%20,string)[\[94\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,). The pagination object provides total pages, total elements, current page, etc.[\[95\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,string). An error field will be present if any issue, and the query field echoes the input parameters (search term, filters)[\[96\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,string). For example:

json { "elements": \[ { "universalName": "\<string\>", "id": "\<string\>", "name": "\<string\>", "industries": "\<string\>", "location": { "linkedinText": "\<string\>" }, "followers": "\<string\>", "summary": "\<string\>", "logo": "\<string\>", "linkedinUrl": "\<string\>" } \], "pagination": { "totalPages": 123, "totalElements": 123, "pageNumber": 123, "previousElements": 123, "pageSize": 123, "paginationToken": "\<string\>" }, "status": "\<string\>", "error": "\<string\>", "query": { "search": "\<string\>", "location": "\<string\>", "geoId": "\<string\>", "companySize": "\<string\>" } }[\[93\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=%7B%20,string)[\[95\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,string)

*(Tips:* You can use **Search GeoID** to find the appropriate geoId for a location to use in this query, as described in the GeoID section.)

### Company Posts

Retrieves LinkedIn posts published by a specific company page. This can be done either by using the general post search with a company filter or via a dedicated endpoint. Note that LinkedIn’s post search may return a limited subset of a company’s posts; to get all posts from a company, HarvestAPI provides a direct company posts scraper[\[97\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company)[\[98\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string).

* **Endpoint:** GET /linkedin/company-posts

* **Query Parameters:**

* company (string, URL of the LinkedIn company page – optional if companyId is used)[\[99\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company)

* companyId (string, LinkedIn company ID – *optional*, but faster if known)[\[100\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=companyId)

* postedLimit (string, filter by post recency; e.g. '24h', 'week', 'month' – *optional*)

* page (integer, page number for pagination, default 1\)

* paginationToken (string, token for next page retrieval if provided by previous response – *optional*)

* **Response:** Returns an elements array of posts (with structure identical to **Search Posts** results – see the LinkedIn Post section). Each post object includes details like id, content text, media attachments (postImages, postVideo, etc.), an author object (which will correspond to the company’s profile info in this case), timestamps (postedAgo), engagement counts (likes, comments, shares, plus breakdown of reactions), etc.[\[101\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=%7B%20,string)[\[102\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=,123%20%7D). Pagination info is included. The example under **Search Posts** below illustrates the post structure.

In practice, to get all posts by a company, you may first need the company’s LinkedIn ID. You can retrieve that via **Get Company** (check the id field) or by searching the company as shown above[\[103\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=How%20to%20get%20Company%20ID)[\[104\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=fetch%28%60https%3A%2F%2Fapi.harvest,company%3F.id%7D%60%29%3B).

*(The “Company posts” functionality can also be accessed by using the Search Posts endpoint with the companyId filter. This endpoint is essentially a convenient scraper for a company’s feed.)*

## LinkedIn Post

### Search Posts

Searches LinkedIn posts across the platform by keywords, author, date, etc. This endpoint allows filtering posts by various criteria such as content keywords, author profile, company, or group, as well as time range and sort order[\[105\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Search%20LinkedIn%20posts)[\[106\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Keywords%20to%20search%20for%20in,posts).

* **Endpoint:** GET /linkedin/post-search

* **Query Parameters:**

* search (string, keywords to search for in post content – *optional*, but usually one of search or an author filter should be provided)[\[107\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=search).

* profile (string, filter to posts authored by a specific person’s profile URL (one or multiple, comma-separated)[\[108\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=profile). *Note:* Using profile will restrict results to that person’s posts, but LinkedIn’s search may not return all of them; see **Profile Posts** for a comprehensive method[\[40\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string).)

* profileId (string, filter to posts by profile ID(s), comma-separated. This is faster than using profile URLs)[\[42\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=profileId).

* company (string, filter to posts by a specific company’s LinkedIn URL(s), comma-separated. For full retrieval of a company’s posts, see **Company Posts** endpoint[\[99\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company).)

* companyId (string, filter to posts by company ID(s), comma-separated, faster than using URLs)[\[100\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=companyId).

* authorsCompany (string, filter to posts whose authors work at specific companies (list company URLs, comma-separated)[\[109\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=extract%20all%20posts%20by%20a,posts%20endpoint).)

* authorsCompanyId (string, filter to posts whose authors work at specific companies by ID(s), comma-separated)[\[110\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=List%20of%20LinkedIn%20companies%20where,separated).

* group (string, filter to posts within a specific LinkedIn group by group URL or group ID)[\[111\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=group).

* postedLimit (string, filter to posts posted within a recent time frame on LinkedIn’s side. Supported values: '24h', 'week', 'month'. This uses LinkedIn’s own search filter)[\[45\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=postedLimit).

* scrapePostedLimit (string, post-filter for recency applied after scraping, with extended options: '1h', '24h', 'week', 'month', '3months', '6months', 'year'. This filter is applied by HarvestAPI after retrieving results)[\[112\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=scrapePostedLimit).

* sortBy (string, sort order of results. Supported: 'relevance' or 'date' for chronological)[\[113\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=sortBy).

* page (integer, page number for pagination, default 1\)[\[114\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Sort%20by%20field,relevance%27%2C%20%27date).

* paginationToken (string, a token required to retrieve pages beyond the first in some cases. If the response to page 1 provides a paginationToken, supply it here for page 2, etc. Often needed when retrieving all posts by a profile or company, as indicated by the response)[\[115\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=paginationToken).

* **Response:** Returns a JSON object with search results in elements (an array of post objects) and pagination info. Each post object contains: an id (post ID), content (the text content of the post, if any), linkedinUrl (URL of the post), and an author object with details of the author (which could be a person or a company)[\[116\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7B%20,string)[\[117\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,true). If the post contains media, there may be a postImages array (for images attached) or a postVideo (for a video thumbnail and link), or an article object if the post shared an article link (with fields like title, subtitle, link, etc.)[\[118\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123%20%7D)[\[119\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,string). The post object can also include repostId and repost (if it’s a repost of another post, with repostedBy info), and for newsletter shares, newsletterUrl and newsletterTitle may appear[\[120\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,string)[\[121\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,).

Each post has a socialContent section which indicates visibility of various counters (flags like hideCommentsCount, hideReactionsCount, etc.) and a shareUrl if available[\[44\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,true). Engagement counts are provided in an engagement object: total likes, comments, shares, and a breakdown of reaction counts by type in a reactions array[\[122\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,%5B)[\[123\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7D%2C%20,123).

Finally, a query object echoes the input parameters used[\[124\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123%20%7D). The example below illustrates one post result (truncated for brevity):

json { "elements": \[ { "id": "\<string\>", "content": "\<string\>", "linkedinUrl": "\<string\>", "author": { "publicIdentifier": "\<string\>", "universalName": "\<string\>", "name": "\<string\>", "linkedinUrl": "\<string\>", "type": true, "info": true, "website": true, "websiteLabel": true, "avatar": { "url": "\<string\>", "width": 123, "height": 123, "expiresAt": 123 } }, "postedAgo": "\<string\>", "postImages": \[ { "url": "\<string\>", "width": 123, "height": 123, "expiresAt": 123 } \], "postVideo": { "thumbnailUrl": "\<string\>", "videoUrl": "\<string\>" }, "article": { "title": "\<string\>", "subtitle": "\<string\>", "link": "\<string\>", "linkLabel": "\<string\>", "description": "\<string\>", "image": { "url": "\<string\>", "width": 123, "height": 123, "expiresAt": 123 } }, "repostId": "\<string\>", "repost": { }, "repostedBy": { "publicIdentifier": "\<string\>", "name": "\<string\>", "linkedinUrl": "\<string\>" }, "newsletterUrl": "\<string\>", "newsletterTitle": "\<string\>", "socialContent": { "hideCommentsCount": true, "hideReactionsCount": true, "hideSocialActivityCounts": true, "hideShareAction": true, "hideSendAction": true, "hideRepostsCount": true, "hideViewsCount": true, "hideReactAction": true, "hideCommentAction": true, "shareUrl": "\<string\>", "showContributionExperience": true, "showSocialDetail": true }, "engagement": { "likes": 123, "comments": 123, "shares": 123, "reactions": \[ { "type": "\<string\>", "count": 123 } \] } } \], "pagination": { ... }, "status": "\<string\>", "error": "\<string\>", "query": { "search": "\<string\>", "profileId": "\<string\>", "companyId": "\<string\>", "postedLimit": "\<string\>", "sortBy": "\<string\>", "page": 123 } }[\[43\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7B%20,string)[\[125\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123)

*(Above, postImages, postVideo, or article will appear depending on the post type. In the example, both an image and an article object are shown for illustrative purposes.)*

### Get Post

Retrieves the full details of a specific LinkedIn post. You must specify the post by its URL or ID. This will return the post’s content, author, media, and engagement metrics.

* **Endpoint:** GET /linkedin/post

* **Query Parameters:**

* url (string, URL of the LinkedIn post – *optional if postId is given*)

* postId (string, the post’s URN ID – *optional*)

*(At least one identifier for the post is required.)*

* **Response:** Returns a JSON object representing the post, in a similar format to a single element from **Search Posts**. It will contain all available fields for that post (content text, author info, media attachments, etc.), as well as aggregated engagement counts and possibly all comments/reactions counts if visible. Using this endpoint, you can retrieve a post’s details directly if you know the post URL or ID, without doing a search.

*(The output structure mirrors the* *Search Posts* *post object shown above, but for a single post. The status, error, and query fields indicate the result and echo the request.)*

### Company posts

*(Alias of the Company Posts endpoint.)* Retrieves posts authored by a given company. For details, see **Company Posts** under the LinkedIn Company section above. You can also use **Search Posts** with the companyId parameter to achieve similar results[\[99\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company)[\[98\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string).

### User posts

*(Equivalent to Profile Posts.)* Retrieves posts authored by a specific user profile. This is the same as the **Profile Posts** endpoint in the LinkedIn Profile section. Use the profile’s LinkedIn URL or ID to fetch their posts. Refer to **Profile Posts** documentation for input and output details.

### Post Comments

Retrieves the comments on a specific LinkedIn post. This endpoint scrapes all top-level comments (and possibly replies) under the given post.

* **Endpoint:** GET /linkedin/post-comments

* **Query Parameters:**

* post (string, URL of the LinkedIn post to get comments from – *required*)

* page (integer, page number of comments to retrieve, default 1\)

*(If a post has a very large number of comments, pagination may be available via subsequent pages.)*

* **Response:** Returns the comments on the post in an elements array. Each comment object will include details similar to those in **Comment reactions** (below) or profile comments: an id, the actor (profile of the commenter with their name, etc.), the comment text (commentary), timestamp (createdAt), and engagement metrics for that comment (e.g. number of replies, reaction counts). If the API also fetches replies to comments, those might be nested or listed as separate elements with a parent reference. Pagination info is provided if multiple pages of comments exist.

*(This endpoint allows you to extract all comments (and possibly a limited number of replies) from a LinkedIn post for analysis of engagement on that post.)*

### Post Reactions

Retrieves the profiles of users who reacted to a given LinkedIn post, grouped by reaction type. It scrapes the “Reactions” pop-up for the post[\[126\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20post)[\[127\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20post,by%20post%20URL).

* **Endpoint:** GET /linkedin/post-reactions

* **Query Parameters:**

* post (string, URL of the LinkedIn post – *required*)[\[128\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=post)

* page (integer, pagination page for reactions, default 1\)[\[129\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=URL%20of%20the%20LinkedIn%20post,required)

* **Response:** Returns an elements array where each element is a reaction entry (very similar in structure to the **Profile Reactions** endpoint, but for a specific post instead of a profile). Each element includes an id (reaction URN), the reactionType (LIKE, CELEBRATE, etc.), the reacting user’s postId reference (which will match the target post’s ID), and an actor object containing the profile info of the person who reacted[\[130\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=%7B%20,linkedinUrl)[\[131\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Xs0LPljAa2PsGpc8%2Curn%3Ali%3Aactivity%3A7330681775884533760%2C0%29,Mohite%20College%20of%20Arts%2C%20Science). Essentially, it lists the users who reacted to the post. The pagination object can be used if the list of reactors is long (LinkedIn typically shows reactors in pages of 50 or so)[\[132\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=page)[\[133\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=200).

**Example:** For a given post, a reaction entry:

json { "id": "urn:li:fsd\_reaction:(urn:li:fsd\_profile:...,urn:li:activity:7330681775884533760,0)", "reactionType": "LIKE", "postId": "7330681775884533760", "actor": { "id": "ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8", "name": "Om More", "linkedinUrl": "https://www.linkedin.com/in/ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8", "position": "Student at ...", "pictureUrl": "...", "picture": { "url": "...", "width": 800, "height": 800, "expiresAt": 1753920000000 } } }[\[134\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=%7B%20,Om%20More)[\[135\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=,%2F0%2F1748166110665%3Fe%3D1753920000%26v%3Dbeta%26t%3DHOMnbBij0Z_MV2RvUu5zLKCpOaN8Cbnh72uqaH99Z%20LA)

*(The full response is wrapped in an object with elements, pagination, status, etc., similar to profile reactions[\[136\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Page%20number%20for%20pagination,is%201)[\[137\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=elements).)*

### Group posts

Searches LinkedIn posts within a specific LinkedIn Group. Use the group filter on **Search Posts** to get posts from a group[\[111\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=group). There is not a separate dedicated endpoint; instead, the general /linkedin/post-search is used with a group identifier.

* **Usage:** Call **Search Posts** with the group parameter set to the group’s URL or ID to retrieve posts from that group. You may combine it with search keywords or other filters if needed (to search within group posts). See **Search Posts** for details on other parameters and the response structure.

*(In the navigation, “Group posts” is listed for convenience, but it utilizes the Search Posts endpoint.)*

### Comment reactions

Retrieves the profiles of users who reacted to a specific comment on a post. In other words, given a single comment, this endpoint returns who liked or reacted to that comment on LinkedIn.

* **Endpoint:** GET /linkedin/comment-reactions

* **Query Parameters:**

* url (string, URL of the LinkedIn comment – *required*)[\[138\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=)

* page (integer, page number for pagination of reactors, default 1\)[\[139\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=URL%20of%20the%20LinkedIn%20comment,required)

* **Response:** Returns an elements array of reaction entries, very much like **Post Reactions** but at the comment level. Each element includes an id (reaction URN for the comment), reactionType (e.g. LIKE), the postId that the comment belongs to, and an actor object for the user who reacted[\[140\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=%7B%20,ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8)[\[141\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=,%2F0%2F1748166110665%3Fe%3D1753920000%26v%3Dbeta%26t%3DHOMnbBij0Z_MV2RvUu5zLKCpOaN8Cbnh72uqaH99Z%20LA). The response format parallels that of **Post Reactions**.

For example, if a comment has likes, each like would be one element with the liker’s profile info. Pagination is included if there are many reactions. The output’s query.url will echo the comment URL provided.

**Note:** LinkedIn comment reaction URLs typically include the comment’s URN. LinkedIn often displays profile URLs in an ID form (e.g. .../in/ACoAAA8BYqEB...) in the reactions popup for comments. HarvestAPI notes that these ID-based profile URLs can’t be directly converted to public profile slugs easily[\[142\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=This%20endpoint%20scrapes%20the%20Reactions,profile%20version%20to%20reduce%20costs). To get the full profile for a reactor, you may need to call **Get Profile** using that ID (as the publicIdentifier or profileId)[\[143\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=ID%20format%2C%20for%20example%3A%20,profile%20version%20to%20reduce%20costs). This is a known limitation due to LinkedIn’s interface for comment reactions.

## LinkedIn Job

### Get Job

Retrieves details of a LinkedIn job posting. Specify the job either by its posting URL or the LinkedIn job ID.

* **Endpoint:** GET /linkedin/job

* **Query Parameters:**

* url (string, URL of the LinkedIn job posting – *optional*)

* jobId (string, LinkedIn job ID – the numeric ID in the job URL, *optional*)

*(Provide one of the above to identify the job.)*

* **Response:** Returns a JSON object with the job’s details, similar to what is displayed on the LinkedIn job posting page. This includes fields such as job title, companyName and company profile link, location, description (the job description text, possibly HTML), employmentType, seniorityLevel, jobFunction, industries, and whether it’s an easyApply job, among other details. It may also include the number of applicants or posting date if available. The structure will have an element object containing these details, plus status, error, and query (echoing the provided URL or ID).

*(This endpoint essentially scrapes the public job posting data. If a job is private or requires login, data may be limited. The search parameter is not used here (unlike companies), since job postings are directly identified by URL or ID.)*

### Search Jobs

Searches LinkedIn jobs by title, company, location, and other filters[\[144\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Search%20Jobs). This endpoint allows programmatic job search similar to LinkedIn’s job search page.

* **Endpoint:** GET /linkedin/job-search

* **Query Parameters:**

* search (string, keywords for job title or keywords – *optional*, can be used instead of title)

* title (string, job title keywords to search – *optional*)[\[145\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=search)

* companyId (string, filter by company ID (employer); one or multiple comma-separated)[\[146\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Search%20jobs%20by%20title)

* location (string, filter by location name text)[\[147\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Filter%20by%20company%20ID,separated)

* geoId (string, filter by location GeoID (overrides location text)[\[148\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Filter%20by%20location%20text))

* sortBy (string, sort order, 'relevance' or 'date')[\[149\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=sortBy)

* workplaceType (string, filter by work arrangement; values: 'office', 'hybrid', 'remote' – can comma-separate multiple)[\[150\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=workplaceType)

* employmentType (string, filter by employment type; values: 'full-time', 'part-time', 'contract', 'temporary', 'internship' – can comma-separate)[\[151\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=employmentType)

* salary (string, filter by salary range; supported thresholds: '40k+', '60k+', '80k+', '100k+', '120k+', '140k+', '160k+', '180k+', '200k+')[\[152\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=salary)

* postedLimit (string, filter by how recently the job was posted; '24h', 'week', 'month')[\[153\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=postedLimit)

* experienceLevel (string, filter by experience level; values: 'internship', 'entry', 'associate', 'mid-senior', 'director', 'executive' – can comma-separate multiple)[\[154\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=experienceLevel)

* industryId (string, filter by industry ID(s), comma-separated. A full list of LinkedIn industry codes is available[\[155\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=industryId).)

* functionId (string, filter by job function ID(s), comma-separated. A list of function codes can be referenced[\[156\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=functionId).)

* under10Applicants (string, if set (e.g. "true"), filter to jobs with fewer than 10 applicants)[\[157\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=search%2Fblob%2Fmain%2F.actor%2Finput_schema.json)

* easyApply (string, set "true" to include only Easy Apply jobs, or "false" to exclude Easy Apply jobs)[\[158\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=easyApply)

* page (integer, page number for pagination, default 1\)[\[159\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Set%20this%20parameter%20to%20%27true%27,can%20be%20%27true%27%20or%20%27false)

* **Response:** Returns a JSON object with found job postings in elements and pagination info. Each job element includes fields such as id (job ID), linkedinUrl (link to the job post), title (job title), postedDate (ISO timestamp of when the job was posted), companyName, companyLink (URL to the company page), companyUniversalName (company’s slug), location (text location of the job), and a boolean easyApply flag[\[160\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=%7B%20,string)[\[161\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,). The pagination object provides the total pages, etc.[\[162\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,string). The query field echoes the input filters (title, companyId, location, etc.)[\[163\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,string)[\[164\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,123).

For example, one element might look like:

json { "id": "\<string\>", "linkedinUrl": "\<string\>", "title": "\<string\>", "postedDate": "2023-11-07T05:31:56Z", "companyName": "\<string\>", "companyLink": "\<string\>", "companyUniversalName": "\<string\>", "location": { "linkedinText": "\<string\>" }, "easyApply": true }[\[160\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=%7B%20,string)

Multiple elements will be in the array, and pagination will detail how to retrieve additional results. The status and error fields indicate success or any errors.

*(This endpoint mirrors LinkedIn’s job search, allowing complex filtering of job listings. Use* *Search GeoID* *to get a geoId for more precise location filtering if needed[\[165\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=geoId).)*

## LinkedIn Group

### Get Group

Retrieves information about a LinkedIn Group. Specify the group by its URL or group ID.

* **Endpoint:** GET /linkedin/group

* **Query Parameters:**

* url (string, LinkedIn Group URL – *optional*)

* groupId (string, LinkedIn Group ID – *optional*)

*(Provide one of the above to identify the group.)*

* **Response:** Returns a JSON object with details of the group in an element field. This typically includes the group’s id, name, description or summary, linkedinUrl, member count (members), and possibly an image (picture) or cover photo if available. It may also list the group’s privacy type or topics if those are public. The structure is analogous to a company or profile response, with status, error, and query reflecting the input.

*(Groups data available via this endpoint is limited to what’s publicly visible about the group. Many group details might require being a member or may not be exposed via scraping.)*

### Search Groups

Searches for LinkedIn Groups by name keywords. Useful for finding group IDs or discovering groups by topic.

* **Endpoint:** GET /linkedin/group-search

* **Query Parameters:**

* search (string, keywords to search in group names – *required*)[\[166\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=search)

* page (integer, page number for pagination, default 1\)[\[167\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=Keywords%20to%20search%20for)

* **Response:** Returns a list of groups matching the search in elements, with pagination info. Each group element includes: id (group ID), linkedinUrl (group URL), name (group title), members (member count as a string), summary (group description/blurb), picture (URL of group image if any), and primaryActions (an array that might indicate if you can join or view, typically includes a label like “+ Join” or similar in the value)[\[168\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=,)[\[169\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=%7B%20,). For example:

json { "elements": \[ { "id": "\<string\>", "linkedinUrl": "\<string\>", "name": "\<string\>", "members": "\<string\>", "summary": "\<string\>", "picture": "\<string\>", "primaryActions": \[ { "label": "\<string\>", "value": "\<string\>" } \] } \], "pagination": { "totalPages": 123, "totalElements": 123, "pageNumber": 123, "previousElements": 123, "pageSize": 123, "paginationToken": "\<string\>" }, "status": "\<string\>", "error": "\<string\>", "query": { "search": "\<string\>" } }[\[170\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=%7B%20,string)[\[171\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=,string)

The primaryActions typically indicates the action a logged-in user could take (for instance, join the group). The search results give basic info to identify the group of interest. The id from here can be used with other endpoints (like **Get Group** or as a filter in post search).

## LinkedIn Ads

### Search Ad Library

Searches LinkedIn’s Ad Library (Ads Transparency data) for ads. You can search by keywords, advertiser name, and filter by country or date. This is analogous to LinkedIn’s Ads Library search page.

* **Endpoint:** GET /linkedin/ad-search

* **Query Parameters:** *(Typical filters include)*

* q or search (string, keywords to search in ad content)

* advertiser (string, name of the advertiser or organization)

* country (string, country code or name to filter ads by the country where they ran)

* startDate / endDate (date range to filter ads by their active dates)

*(Exact parameter names may vary; the API supports searching ads by keyword, advertiser, and country, and narrowing by date range[\[172\]](https://www.searchapi.io/linkedin-ad-library-api#:~:text=Programmatic%20access%20to%20LinkedIn%27s%20advertising,transparency%20data).)*

* **Response:** Returns a structured JSON of ad search results. Each result (ad creative) might include fields such as the advertiser name and thumbnail logo, the ad\_type (format of the ad, e.g. image, video, document), the ad content (e.g. headline and text), and possibly an array of media (images or videos used in the ad) with URLs[\[173\]](https://www.searchapi.io/linkedin-ad-library-api#:~:text=,N4vlyg5Ur3atekw%22%20%7D%2C%20%22ad_type%22%3A%20%22document). Other metadata could include the position (rank in results), and maybe an adId. The response also provides a total\_results count and echo of the search parameters.

*(This enables programmatic access to LinkedIn’s ad transparency data. For example, searching for “tesla” might return recent ads containing that keyword, along with advertiser info and ad copy[\[172\]](https://www.searchapi.io/linkedin-ad-library-api#:~:text=Programmatic%20access%20to%20LinkedIn%27s%20advertising,transparency%20data).)*

### Get Ad Details

Retrieves detailed metadata of a specific LinkedIn ad (from the Ad Library) given an identifier. This would provide the full content and performance metrics of the ad if available (impressions, etc.), and all associated information like when it ran and targeting data if exposed.

* **Endpoint:** GET /linkedin/ad (or /linkedin/ad-details)

* **Query Parameters:**

* adId (string, the unique identifier of the ad in the Ad Library – *required*)

* **Response:** Returns a JSON object with all details of the specified ad. This typically includes the advertiser info, full text content, media assets, call-to-action button text if any, start and end run dates, countries where the ad ran, and possibly summary performance data (number of impressions, etc.) if provided by LinkedIn’s transparency feed. It mirrors what one would see by clicking an ad in the Ad Library interface.

*(This endpoint allows retrieving a single ad’s data after using Search Ad Library to find an adId or similar. The exact format will align with the fields in the Ad Library JSON, including all metadata and creative elements for that ad.)*

## LinkedIn Services

### Search Profile Services

Searches LinkedIn’s Service Providers (freelancers or businesses offering services on LinkedIn). LinkedIn offers a separate “service provider” search for members who have listed services (e.g. consulting, design, etc.)[\[174\]](https://docs.harvest-api.com/guides/profile-search#:~:text=Linkedin%20service%20search%20scraper)[\[175\]](https://docs.harvest-api.com/guides/profile-search#:~:text=LinkedIn%20has%20a%20separate%20search,To%20scrape%20the%20services). This endpoint scrapes that service search.

* **Endpoint:** GET /linkedin/service-search (also referred to as **Search Profile Services**)

* **Query Parameters:**

* search (string, keywords for the service or skill – *required*)

* location (string, filter by location name – *optional*)

* geoId (string, filter by location GeoID – *optional*)

* page (integer, page number, default 1\)

*(This search does not hide profiles behind "LinkedIn Member" anonymity, making it possible to get up to 1000 profiles (100 pages \* 10 per page) for a given query, though the total pool might be smaller than the basic search[\[175\]](https://docs.harvest-api.com/guides/profile-search#:~:text=LinkedIn%20has%20a%20separate%20search,To%20scrape%20the%20services).)*

* **Response:** Returns a list of profiles offering services matching the query, similar to **Search Profiles** but specifically from the Services search context. Each result likely includes the provider’s name, title, location, and a link to their profile, as well as possibly the service category or tagline they have on their service page. The JSON format will have elements (profiles) and pagination similar to profile search results. Each profile element might also include a snippet of their service introduction or a list of services they offer.

*(Use this to find freelancers/SMBs on LinkedIn by service expertise. For example, searching “Web design” in “California” via this endpoint yields a set of profiles in California offering web design services, up to 1000 results[\[175\]](https://docs.harvest-api.com/guides/profile-search#:~:text=LinkedIn%20has%20a%20separate%20search,To%20scrape%20the%20services).)*

## LinkedIn GeoID

### Search GeoID

Looks up LinkedIn location GeoIDs by location name. This helper endpoint uses LinkedIn’s location autocomplete API to find the internal GeoID for a given location query[\[176\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=Search%20GeoID). GeoIDs are useful for refined filtering in profile and job searches.

* **Endpoint:** GET /linkedin/geo-id-search

* **Query Parameters:**

* search (string, the location name or partial name to search – *required*)[\[177\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=)

* **Response:** Returns a JSON object containing the matched locations. The fields include: elements – an array of location results (each with a geoId and title for the location)[\[178\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=%7B%20,)[\[179\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,%7D%20%5D), as well as possibly an entityId for the top/closest match (for convenience). The response also contains the input search query, and status/error fields[\[180\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,%5B)[\[181\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,). For example:

json { "id": "\<string\>", "query": { "search": "New York" }, "status": "\<string\>", "error": "\<string\>", "elements": \[ { "geoId": "102221843", "title": "New York, United States" }, { "geoId": "102228460", "title": "New York, NY" }, { "geoId": "104514572", "title": "New York City Metropolitan Area" } // ...other matches... \] }[\[182\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=%7B%20,)[\[183\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,)

In addition, the API often provides entityId as the single best match’s GeoID (e.g. for “New York” it might pick the city)[\[184\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=const%20apiKey%20%3D%20%27%3Capi)[\[185\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=.then%28%28response%29%20%3D,entityId). You can use the returned GeoIDs in other endpoints (e.g., geoId in **Search Profiles** or **Search Jobs**) for precise location filtering. This endpoint is especially useful when a location name is ambiguous or when LinkedIn’s search requires the numeric location code for accurate results[\[186\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,use%20for%20the%20API%20endpoint)[\[187\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=of%20,use%20for%20the%20API%20endpoint).

---

[\[1\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=) [\[2\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Get%20the%20LinkedIn%20profile%20of,one%20of%20the%20query%20parameters) [\[3\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=url) [\[4\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=url) [\[5\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=publicIdentifier) [\[6\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Public%20identifier%20of%20the%20LinkedIn,optional) [\[7\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=main) [\[8\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=string) [\[9\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=Include%20this%20parameter%20to%20find,This%20version%20charges%20more%20credits) [\[10\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=skipSmtp) [\[11\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=includeAboutProfile) [\[12\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=%7B%20,string) [\[13\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=,) [\[14\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=%7B%20,string) [\[15\]](https://docs.harvest-api.com/linkedin-api-reference/profile/get#:~:text=,) Get Profile \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/profile/get](https://docs.harvest-api.com/linkedin-api-reference/profile/get)

[\[16\]](https://docs.harvest-api.com/guides/profile-search#:~:text=Lead%20search%20scraper) [\[17\]](https://docs.harvest-api.com/guides/profile-search#:~:text=The%20Lead%20search%20endpoint%20scrapes,scale%20scraping%20volumes) [\[174\]](https://docs.harvest-api.com/guides/profile-search#:~:text=Linkedin%20service%20search%20scraper) [\[175\]](https://docs.harvest-api.com/guides/profile-search#:~:text=LinkedIn%20has%20a%20separate%20search,To%20scrape%20the%20services) LinkedIn profile search scraper \- HarvestAPI

[https://docs.harvest-api.com/guides/profile-search](https://docs.harvest-api.com/guides/profile-search)

[\[18\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Search%20Profiles) [\[19\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=This%20is%20basic%20profile%20search,this%20information%20before%20using%20it) [\[20\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,) [\[21\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=const%20params%20%3D%20new%20URLSearchParams%28,key%3E%27) [\[22\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=currentCompany) [\[23\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20company%20ID%20or,separated) [\[24\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=school) [\[25\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=firstName) [\[26\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20first%20name) [\[27\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20last%20name) [\[28\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20title) [\[29\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20location%20text) [\[30\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=industryId) [\[31\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20industry%20ID,separated) [\[32\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=Filter%20by%20keywords%20in%20company,name) [\[33\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=page) [\[34\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=%7B%20,) [\[35\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,123) [\[36\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,string) [\[37\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,) [\[38\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=%7B%20,string) [\[39\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,%7D) [\[184\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=const%20apiKey%20%3D%20%27%3Capi) [\[185\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=.then%28%28response%29%20%3D,entityId) [\[186\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=,use%20for%20the%20API%20endpoint) [\[187\]](https://docs.harvest-api.com/linkedin-api-reference/profile/search#:~:text=of%20,use%20for%20the%20API%20endpoint) Search Profiles \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/profile/search](https://docs.harvest-api.com/linkedin-api-reference/profile/search)

[\[40\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string) [\[41\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Filter%20posts%20by%20author%27s%20profile,posts%20endpoint) [\[42\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=profileId) [\[43\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7B%20,string) [\[44\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,true) [\[45\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=postedLimit) [\[46\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=paginationToken) [\[97\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company) [\[98\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=string) [\[99\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=company) [\[100\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=companyId) [\[105\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Search%20LinkedIn%20posts) [\[106\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Keywords%20to%20search%20for%20in,posts) [\[107\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=search) [\[108\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=profile) [\[109\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=extract%20all%20posts%20by%20a,posts%20endpoint) [\[110\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=List%20of%20LinkedIn%20companies%20where,separated) [\[111\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=group) [\[112\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=scrapePostedLimit) [\[113\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=sortBy) [\[114\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=Sort%20by%20field,relevance%27%2C%20%27date) [\[115\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=paginationToken) [\[116\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7B%20,string) [\[117\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,true) [\[118\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123%20%7D) [\[119\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,string) [\[120\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,string) [\[121\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,) [\[122\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,%5B) [\[123\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=%7D%2C%20,123) [\[124\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123%20%7D) [\[125\]](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts#:~:text=,123) Group posts \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/post/group-posts](https://docs.harvest-api.com/linkedin-api-reference/post/group-posts)

[\[47\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Get%20comments%20made%20by%20a,activity%2Fcomments) [\[48\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=profile) [\[49\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=URL%20of%20the%20LinkedIn%20profile) [\[50\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=postedLimit) [\[51\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Filter%20posts%20by%20maximum%20posted,Supported%20values%3A%20%2724h%27%2C%20%27week%27%2C%20%27month) [\[52\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=Page%20number%20for%20pagination,is%201) [\[53\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=%7B%20,29%22%2C%20%22commentary%22%3A%20%22Exciting) [\[54\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,Public%20Sector%20Projects) [\[55\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,) [\[56\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=%7B%20,123) [\[57\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=,string) [\[58\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=) [\[59\]](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments#:~:text=const%20params%20%3D%20new%20URLSearchParams%28,key%3E%27) Profile comments \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments](https://docs.harvest-api.com/linkedin-api-reference/profile/profile-comments)

[\[60\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20profile) [\[61\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=LinkedIn%20Profile) [\[62\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=profile) [\[63\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=URL%20of%20the%20LinkedIn%20profile) [\[64\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Profile%20ID%20of%20the%20LinkedIn,faster%20to%20search%20by%20ID) [\[65\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Page%20number%20for%20pagination,is%201) [\[66\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=%7B%20,linkedinUrl) [\[67\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Xs0LPljAa2PsGpc8%2Curn%3Ali%3Aactivity%3A7330681775884533760%2C0%29,Mohite%20College%20of%20Arts%2C%20Science) [\[68\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=) [\[69\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=Post%20reactions%20response) [\[70\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=,s%20hrink_800_800%2FB4EZcG0eZVHAAc) [\[71\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=200) [\[72\]](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions#:~:text=elements) Profile reactions \- HarvestAPI

[https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions](https://elrix.mintlify.app/linkedin-api-reference/profile/profile-reactions)

[\[73\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=url) [\[74\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=search) [\[75\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=url) [\[76\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=URL%20of%20the%20LinkedIn%20company,optional) [\[77\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string) [\[78\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string) [\[79\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string) [\[80\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=%7D%2C%20,string) [\[81\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string) [\[82\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,%5B) [\[83\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=%7D%2C%20,string) [\[84\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,) [\[85\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,%7D) [\[86\]](https://docs.harvest-api.com/linkedin-api-reference/company/get#:~:text=,string) Get Company \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/company/get](https://docs.harvest-api.com/linkedin-api-reference/company/get)

[\[87\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=Search%20Companies) [\[88\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=search) [\[89\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=Keywords%20to%20search%20for%20in,company%20names) [\[90\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=geoId) [\[91\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=companySize) [\[92\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=%2710001%2B%27) [\[93\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=%7B%20,string) [\[94\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,) [\[95\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,string) [\[96\]](https://docs.harvest-api.com/linkedin-api-reference/company/search#:~:text=,string) Search Companies \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/company/search](https://docs.harvest-api.com/linkedin-api-reference/company/search)

[\[101\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=%7B%20,string) [\[102\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=,123%20%7D) [\[103\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=How%20to%20get%20Company%20ID) [\[104\]](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts#:~:text=fetch%28%60https%3A%2F%2Fapi.harvest,company%3F.id%7D%60%29%3B) Company posts \- HarvestAPI

[https://elrix.mintlify.app/linkedin-api-reference/post/company-posts](https://elrix.mintlify.app/linkedin-api-reference/post/company-posts)

[\[126\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20post) [\[127\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Get%20reactions%20of%20LinkedIn%20post,by%20post%20URL) [\[128\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=post) [\[129\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=URL%20of%20the%20LinkedIn%20post,required) [\[130\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=%7B%20,linkedinUrl) [\[131\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Xs0LPljAa2PsGpc8%2Curn%3Ali%3Aactivity%3A7330681775884533760%2C0%29,Mohite%20College%20of%20Arts%2C%20Science) [\[132\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=page) [\[133\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=200) [\[134\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=%7B%20,Om%20More) [\[135\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=,%2F0%2F1748166110665%3Fe%3D1753920000%26v%3Dbeta%26t%3DHOMnbBij0Z_MV2RvUu5zLKCpOaN8Cbnh72uqaH99Z%20LA) [\[136\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=Page%20number%20for%20pagination,is%201) [\[137\]](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions#:~:text=elements) Post reactions \- HarvestAPI

[https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions](https://elrix.mintlify.app/linkedin-api-reference/post/post-reactions)

[\[138\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=) [\[139\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=URL%20of%20the%20LinkedIn%20comment,required) [\[140\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=%7B%20,ACoAAFsSba4BjCtAJXsUcfwXs0LPljAa2PsGpc8) [\[141\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=,%2F0%2F1748166110665%3Fe%3D1753920000%26v%3Dbeta%26t%3DHOMnbBij0Z_MV2RvUu5zLKCpOaN8Cbnh72uqaH99Z%20LA) [\[142\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=This%20endpoint%20scrapes%20the%20Reactions,profile%20version%20to%20reduce%20costs) [\[143\]](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions#:~:text=ID%20format%2C%20for%20example%3A%20,profile%20version%20to%20reduce%20costs) Comment reactions \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions](https://docs.harvest-api.com/linkedin-api-reference/post/comment-reactions)

[\[144\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Search%20Jobs) [\[145\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=search) [\[146\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Search%20jobs%20by%20title) [\[147\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Filter%20by%20company%20ID,separated) [\[148\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Filter%20by%20location%20text) [\[149\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=sortBy) [\[150\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=workplaceType) [\[151\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=employmentType) [\[152\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=salary) [\[153\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=postedLimit) [\[154\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=experienceLevel) [\[155\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=industryId) [\[156\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=functionId) [\[157\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=search%2Fblob%2Fmain%2F.actor%2Finput_schema.json) [\[158\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=easyApply) [\[159\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=Set%20this%20parameter%20to%20%27true%27,can%20be%20%27true%27%20or%20%27false) [\[160\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=%7B%20,string) [\[161\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,) [\[162\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,string) [\[163\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,string) [\[164\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=,123) [\[165\]](https://elrix.mintlify.app/linkedin-api-reference/job/search#:~:text=geoId) Search Jobs \- HarvestAPI

[https://elrix.mintlify.app/linkedin-api-reference/job/search](https://elrix.mintlify.app/linkedin-api-reference/job/search)

[\[166\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=search) [\[167\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=Keywords%20to%20search%20for) [\[168\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=,) [\[169\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=%7B%20,) [\[170\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=%7B%20,string) [\[171\]](https://docs.harvest-api.com/linkedin-api-reference/group/search#:~:text=,string) Search Groups \- HarvestAPI

[https://docs.harvest-api.com/linkedin-api-reference/group/search](https://docs.harvest-api.com/linkedin-api-reference/group/search)

[\[172\]](https://www.searchapi.io/linkedin-ad-library-api#:~:text=Programmatic%20access%20to%20LinkedIn%27s%20advertising,transparency%20data) [\[173\]](https://www.searchapi.io/linkedin-ad-library-api#:~:text=,N4vlyg5Ur3atekw%22%20%7D%2C%20%22ad_type%22%3A%20%22document) LinkedIn Ad Library API

[https://www.searchapi.io/linkedin-ad-library-api](https://www.searchapi.io/linkedin-ad-library-api)

[\[176\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=Search%20GeoID) [\[177\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=) [\[178\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=%7B%20,) [\[179\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,%7D%20%5D) [\[180\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,%5B) [\[181\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,) [\[182\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=%7B%20,) [\[183\]](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search#:~:text=,) Search GeoID \- HarvestAPI

[https://elrix.mintlify.app/linkedin-api-reference/geo-id/search](https://elrix.mintlify.app/linkedin-api-reference/geo-id/search)