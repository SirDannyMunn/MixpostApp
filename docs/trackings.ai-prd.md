# **Trackings.ai \- Comprehensive Product Requirements Document**

## **Executive Summary**

**Trackings.ai** is an AI-powered visibility tracker that monitors brand/website rankings across major AI search engines (ChatGPT, Claude, Gemini, Perplexity, Google AI Overviews) and integrates Reddit automation for lead discovery and community engagement. The platform is designed for SEO agencies, freelancers, and businesses looking to track their presence in AI-generated search results and leverage Reddit for organic growth.

---

## **1\. PRODUCT OVERVIEW**

### **Product Vision**

Monitor and optimize brand visibility across AI search engines while automating Reddit engagement to drive traffic and qualified leads.

### **Core Use Cases**

* Track keyword rankings across AI platforms (ChatGPT, Claude, Gemini, Perplexity, Google AI Overviews)  
* Discover competitor ranking gaps  
* Automate Reddit community engagement and lead discovery  
* Generate AI-powered insights on why rankings change  
* Create automated reports for clients  
* Monitor real-time ranking shifts and competitor moves

  ### **Target Users**

* SEO Agencies (500+ agencies already use the platform)  
* Digital Marketing Freelancers  
* In-house SEO Teams  
* Content Creators  
* E-commerce Businesses  
* SaaS Companies  
  ---

  ## **2\. CORE FEATURES & MODULES**

  ### **2.1 AI Rank Tracker (Primary Module)**

The flagship feature that monitors keyword rankings across all major AI search engines.

**Key Capabilities:**

* **Multi-Platform Tracking**: Monitor rankings across ChatGPT, Claude, Gemini, Perplexity, and Google AI Overviews simultaneously  
* **10,000+ Daily Keywords**: AI scans and tracks thousands of keywords daily  
* **Unlimited Keywords Per Project**: Users can track unlimited keywords (even on Starter plan)  
* **Tracking Frequency Options**: Daily, weekly, or monthly scans based on plan  
* **Real-Time Alerts**: Instant notifications when ranking positions change significantly  
* **Competitor Benchmarking**: Select and track competitor rankings day-by-day with delta tracking  
* **Automatic Project Setup**: Connect Google Search Console or paste keywords for instant setup  
* **Historical Data Tracking**: See ranking trends over weeks and months  
* **AI-Powered Insights**: AI explains why rankings dropped or jumped with actionable recommendations

**How It Works (Likely Technology Stack):**

* Automated API calls to ChatGPT, Claude, Gemini, Perplexity APIs to query keywords and capture SERP positions  
* Web scraping for Google AI Overviews (CSS parsing)  
* Daily batch job runners that execute scans on schedule  
* Database storage (likely PostgreSQL) to store historical rankings  
* Comparison algorithm to identify position changes between scans  
* OpenAI/Claude API calls to generate AI insights on why rankings changed  
* Real-time notification system (Webhooks/WebSockets)  
  ---

  ### **2.2 Keyword Research Tool**

Discovers high-ROI keywords and identifies competitive gaps.

**Key Capabilities:**

* **AI Search Volume Metrics**: Shows "AI Search Volume" (keywords mentioned in AI responses) vs. "Google Search Volume"  
* **Keyword Difficulty Scoring**: Estimates difficulty to rank for each keyword  
* **Competitor Gap Analysis**: Shows keywords competitors rank for but you don't  
* **One-Click Import**: Add discovered keywords directly to tracking projects  
* **Intent Scoring**: Categorizes keyword intent for prioritization  
* **Trend Analysis**: Shows keyword popularity trends

**How It Works:**

* Uses keyword databases (likely Ahrefs API or similar) for Google search volume  
* Queries AI platforms with seed keywords to determine which keywords appear in AI responses  
* Processes results to count mentions and identify patterns  
* Calculates keyword difficulty using SEO metrics APIs  
* Machine learning model to score buying intent based on keyword characteristics

**Credit Cost**: 10 credits per keyword analyzed

---

### **2.3 Brand Explorer**

Provides instant visibility snapshots across all AI platforms.

**Key Capabilities:**

* **Cross-Platform Brand Visibility Report**: Single report showing brand mentions across ChatGPT, Claude, Gemini, Perplexity, and Google AI Overviews  
* **Citation Tracking**: Shows how many times brand is cited  
* **Data Source Identification**: Lists which domains/sources are cited as references  
* **Quick Snapshots**: Generates reports in minutes, not hours  
* **Quarterly Review Ready**: Designed for executive/client presentations

**How It Works:**

* Queries each AI platform with brand name/domain  
* Captures response content and identifies citations  
* Aggregates data across all platforms into single dashboard view  
* Analyzes content to identify sentiment and context of mentions

**Credit Cost**: 200 credits per report

---

### **2.4 Social Boost (Reddit Automation)**

Automates Reddit monitoring, engagement, and comment posting.

**Sub-Features:**

**A. Reddit Monitoring & Discovery**

* **SERP Monitoring**: Tracks which Reddit threads appear in search results for tracked keywords  
* **Real-Time Monitoring**: Scans Reddit 24/7 for threads matching keywords  
* **Thread Metrics**: Shows upvotes, comments, engagement on relevant threads  
* **Intent Scoring**: AI rates each Reddit thread for relevance and opportunity

**B. AI Comment Generation & Posting**

* **Auto-Generate Comments**: AI generates contextual replies based on thread discussion  
* **Manual Review Option**: Moderators can review before posting  
* **Autopilot Mode**: Fully automated comment posting to relevant threads  
* **Comment Context**: Shows full thread conversation for human-like engagement

**C. Comment Engagement**

* **Upvote Boost Campaigns**: Purchase upvotes to increase comment visibility  
* **Reply Tracking**: Monitor when others reply to your comments  
* **Comment Rank Tracking**: Track where your comments rank within thread discussions  
* **Rank Improvement**: See which comments get highest engagement

**D. Data Management**

* **CSV Export**: Export all Reddit data for external analysis  
* **View All Comments**: See complete thread context  
* **Share Reports**: Generate shareable links to campaign results

**How It Works:**

* Integrates with Reddit API to search and monitor subreddits  
* Uses natural language processing to identify relevant threads  
* Implements prompt engineering to generate contextual AI responses  
* Posts comments via Reddit API with built-in rate limiting  
* Tracks comment performance through Reddit API calls  
* Machine learning model scores engagement likelihood

**Included Features by Plan**: Varies by tier (Autopilot only on Scale+ plans)

---

### **2.5 Find Leads (AI-Powered Lead Discovery)**

Discovers and qualifies high-intent leads from Reddit conversations.

**Key Capabilities:**

* **Real-Time Monitoring**: Scans Reddit 24/7 for people discussing problems your product solves  
* **Intent Scoring (0-100)**: AI rates buying intent based on pain points, urgency, and signals  
* **Lead Qualification**: Provides detailed lead profiles with context  
* **Engagement Ready**: Suggests personalized outreach approaches  
* **Daily Digests**: Scheduled reports of fresh qualified leads  
* **Conversion Tracking**: Monitor outreach responses and conversion outcomes

**How It Works:**

* Natural Language Processing analyzes Reddit comments for buying signals/pain points  
* Machine learning model trained to identify high-intent language patterns  
* Scoring algorithm considers: problem description depth, urgency language, solution-seeking behavior, budget hints  
* Extracts Reddit user information for outreach context  
* Tracks engagement results when you respond  
  ---

  ### **2.6 Sentiment Analysis**

Analyzes how AI platforms perceive and describe a brand.

**Key Capabilities:**

* Tracks sentiment of brand mentions in AI responses  
* Identifies perception differences across platforms  
* Shows how brands are described contextually  
* Helps understand brand positioning in AI outputs

**How It Works:**

* Queries AI platforms with brand name  
* Extracts response text  
* Uses sentiment analysis model (VADER or transformer-based) to classify positive/negative  
* Analyzes description context to understand positioning

**Credit Cost**: 200 credits per report

---

### **2.7 Additional Tools & Features**

**Dashboard & Analytics**

* **Visual Ranking Overview**: Charts showing position changes over time  
* **SERP Coverage Metrics**: What percentage of searches show your site  
* **Rank Distribution**: Visual breakdown of rankings across position ranges (Top 3, Top 10, Top 50, etc.)  
* **Competitor Comparison**: Side-by-side performance comparison over any date range  
* **Historical Trend Charts**: Week-over-week and month-over-month growth

**Recurring Scans**

* Automatic scheduling of scans (daily, weekly, monthly)  
* No manual intervention needed after setup

**Reports & Sharing**

* **Client-Ready Reports**: Auto-generated, branded reports  
* **Shareable Links**: Generate links to share with stakeholders  
* **Export to CSV**: Export all data for external analysis  
* **White-Label Option** (Enterprise): Custom branded reports

**Project Management**

* Unlimited projects per account (even on Starter)  
* Search and organize projects  
* Status tracking (active, paused)  
  ---

  ## **3\. CREDIT SYSTEM**

  ### **How Credits Work**

Credits are the currency of Trackings.ai. Each action costs a specific number of credits. Monthly credits reset every billing cycle.

### **Credit Costs by Feature**

| Feature | Cost |
| ----- | ----- |
| Keyword Research | 10 credits per keyword |
| Brand Explorer | 200 credits per report |
| Sentiment Analysis | 200 credits per report |
| AI Rank Tracker Scan | Built into plan (unlimited) |
| Reddit Monitoring | Built into plan |
| Additional Credit Pack | Available for purchase |

### **Monthly Credit Allocation by Plan**

* **Starter**: 700 credits/month  
* **Growth**: 5,500 credits/month  
* **Scale**: 30,000 credits/month  
* **Enterprise**: 150,000 credits/month  
  ---

  ## **4\. SUBSCRIPTION PLANS & PRICING**

  ### **Pricing Model**

All plans feature "lifetime pricing secured" \- rates are locked forever once you subscribe.

### **Plan Comparison**

| Feature | Starter | Growth | Scale | Enterprise |
| ----- | ----- | ----- | ----- | ----- |
| **Monthly Price** | $47 | $147 | $497 | $1,997 |
| **Annual Price** | $470 | $1,470 | $4,970 | $19,970 |
| **Annual Savings** | 17% (2 months free) | 17% (2 months free) | 17% (2 months free) | 17% (2 months free) |
| **Monthly Credits** | 700 | 5,500 | 30,000 | 150,000 |
| **Tracked Keywords** | \~70 | \~550 | \~3,000 | \~15,000 |
| **Target User** | Solo/Freelancer | Small Agencies | Agencies/Enterprises | High-Volume Teams |

### **Included in All Plans**

* ✅ Unlimited projects  
* ✅ Competitor tracking  
* ✅ Signals (Reddit monitoring)  
* ✅ Discover (Reddit research)  
* ✅ AI Reddit comment generation  
* ✅ Tracking frequency (daily, weekly, monthly)  
* ✅ All AI platform coverage (ChatGPT, Claude, Gemini, Perplexity, Google AI)

  ### **Plan-Specific Features**

**Growth Plan Additions**

* Priority email support

**Scale Plan Additions** (Most Popular)

* Autopilot Reddit campaigns (fully automated posting)  
* Dedicated account manager

**Enterprise Plan Additions**

* API access  
* Custom workflows  
* Quarterly strategy calls  
* Dedicated manager

  ### **Additional Add-Ons (Available to All Plans)**

* **Reddit Automations**: Turn keywords into automated Reddit monitoring and reply workflows  
* **Keyword Research**: Run independent keyword discovery campaigns  
* **Brand Explorer**: Generate visibility snapshots (200 credits each)  
* **Traffic Analyzer**: Estimate Reddit traffic value and ROI for reports  
  ---

  ## **5\. LIKELY TECHNOLOGY ARCHITECTURE**

  ### **Frontend Stack**

* **Framework**: React or Vue.js (modern, component-based)  
* **State Management**: Redux or Zustand  
* **UI Components**: Custom or Material-UI for dashboards  
* **Charting**: Chart.js or D3.js for ranking visualizations  
* **Real-time Updates**: WebSockets for live alerts and notifications

  ### **Backend Stack**

* **Framework**: Node.js/Express, Python/Django, or Ruby on Rails  
* **Database**: PostgreSQL for relational data (rankings, users, projects)  
* **Cache Layer**: Redis for quick access to recent rankings  
* **Job Queue**: Bull/BullMQ or Celery for scheduled scans

  ### **Third-Party Integrations**

* **OpenAI API** (ChatGPT access and text analysis)  
* **Anthropic Claude API** (for ranking queries and insight generation)  
* **Google Gemini API** (ranking queries)  
* **Perplexity API** (ranking queries)  
* **Reddit API** (thread monitoring, comment posting, data collection)  
* **Google Search Console API** (for keyword import)  
* **SEO Data APIs** (Ahrefs, SemRush, or similar for keyword metrics)  
* **Stripe** (payment processing)

  ### **Data Collection Methods**

**For AI Rank Tracking:**

* Direct API calls to each AI platform with user keywords  
* Captures returned SERP positions and content  
* Stores results in time-series database  
* Compares positions against previous scans  
* Flags significant changes

**For Brand Mentions (Brand Explorer):**

* Queries: "How is \[brand\] used in \[industry\]?"  
* Analyzes citations in responses  
* Extracts domain names and frequency  
* Creates aggregated report

**For Reddit Monitoring:**

* Reddit API PRAW (Python Reddit API Wrapper) integration  
* Subreddit scanning for keyword matches  
* Thread metadata collection  
* Comment monitoring  
* Engagement tracking

  ### **Data Processing Pipeline**

1. **Collection Phase**: Scheduled batch jobs query AI platforms and Reddit  
2. **Processing Phase**: Parse responses, extract rankings, calculate changes  
3. **Analysis Phase**: ML models analyze intent, sentiment, engagement  
4. **Storage Phase**: Store to database with timestamps  
5. **Alert Phase**: Trigger notifications for significant changes  
6. **Reporting Phase**: Generate insights and compile reports

   ### **Insight Generation (How AI Insights Work)**

* Feeds ranking change data \+ historical context to LLM  
* Prompt engineering to get specific, actionable recommendations  
* Example: "Keywords dropped from position 2-8. Why? \[shows SERP history\]. What to do?"  
* Uses temperature settings to balance creativity vs consistency  
* Likely uses Claude or GPT-4 for high-quality analysis  
  ---

  ## **6\. DATA SOURCES**

  ### **Global Data Tracked (Dashboard shows)**

* 5,281 domains with citations  
* 20,391 total citations  
* Data collected from: Claude, ChatGPT, Gemini, Perplexity, Google AI Overviews  
* Top sources include: amazon.com (298), yelp.com (288), imkatconstruction.com (264), Home Depot (130), WebMD (various)  
  ---

  ## **7\. USER INTERFACE & EXPERIENCE**

  ### **Main Navigation**

* Dashboard (welcome & overview)  
* Get Started (onboarding)  
* Brand Explorer (visibility snapshots)  
* Keyword Research (keyword discovery)  
* Social Boost (Reddit automation)  
* Find Leads (Reddit lead discovery)  
* AI Rank Tracker (project management)  
* Help Center (documentation \+ AI assistant)

  ### **Key UX Features**

* **Credit Usage Tracking**: Left sidebar shows credits used/remaining with progress bar  
* **Quick Actions**: "Create New Scan" button for rapid project creation  
* **Sample Projects**: Preview mode shows Nike Store, Local Coffee Shop, Tech Startup examples  
* **AI Assistant**: Built-in help chatbot with common questions  
* **Video Tutorials**: "Watch tutorial first" banner with intro videos  
* **Real-time Notifications**: Alt+T notification system  
  ---

  ## **8\. COMPETITIVE POSITIONING**

  ### **What Differentiates Trackings.ai**

1. **AI-First Focus**: Only tracks AI search engine rankings, not just Google  
2. **Reddit Integration**: Unique focus on Reddit for engagement and lead generation  
3. **Automated Insights**: AI-generated explanations for ranking changes  
4. **Lifetime Pricing**: Lock in rates forever, no price increases  
5. **All-in-One Platform**: Combines tracking \+ keyword research \+ Reddit automation  
6. **Affordable**: $47/month entry point vs. $100+ for traditional SEO tools  
7. **White-Label Ready**: Agencies can rebrand reports (Enterprise)  
8. **New Market**: Tracks emerging AI search landscape not covered by traditional tools

   ### **Key Claims**

* Saves 5 hours per week on reporting (down to 20 minutes)  
* 500+ agencies already using platform  
* Catches ranking drops before clients notice  
* Identifies high-ROI keywords automatically  
* Real-time alerts prevent lost opportunities  
  ---

  ## **9\. LIKELY REVENUE MODEL**

* **Monthly/Annual Subscriptions**: Primary revenue  
* **Credit-Based Pricing**: Additional revenue from keyword research and reports  
* **Additional Features**: Traffic Analyzer, Brand Explorer purchases  
* **Lifetime Pricing Lock**: Strategy to reduce churn and increase LTV

**Estimated Revenue Potential:**

* Assuming 500+ agencies average $300/month \= $150,000+ MRR  
* Additional credit sales estimated at 20-30% premium  
  ---

  ## **10\. FREE TIER LIMITATIONS**

The free plan offers limited features:

* View-only of dashboards  
* Can't run scans without upgrade  
* 0 credits to spend  
* Preview-mode only  
* Can see examples but not execute  
  ---

  ## **11\. FUTURE FEATURES (Based on Roadmap Hints)**

The billing page mentions features team wants to build:

* Advanced Analytics (deep AI ranking insights)  
* More AI Platforms (track emerging engines)  
* White-Label Reports (custom branded)  
* API Access (already in Enterprise)  
* Competitor Analysis (advanced)  
* Alerts & Monitoring (real-time notifications)  
  ---

  ## **12\. KEY METRICS & DATA POINTS**

* **10,000+ keywords tracked daily**  
* **24/7 Reddit monitoring**  
* **5 AI platforms covered** (ChatGPT, Claude, Gemini, Perplexity, Google AI)  
* **Real-time alert system**  
* **Client-ready reports in 20 minutes** (vs. 5 hours manual)  
* **500+ agencies using platform**  
* **Lifetime pricing** (rates locked forever)  
  ---

  ## **13\. SUPPORT & DOCUMENTATION**

* **AI Assistant**: Built-in chatbot for instant help  
* **Help Center**: Organized by feature (Getting Started, Rank Tracker, Keyword Research, Sentiment Analysis, Social Boost, Billing & Credits, Troubleshooting)  
* **Knowledge Base**: 40+ articles covering setup, troubleshooting, billing  
* **Email Support**: support@trackings.ai  
* **Priority Support**: Available on Growth+ plans  
  ---

  ## **Summary**

**Trackings.ai** is a comprehensive AI visibility and Reddit automation platform targeting SEO agencies and content creators. Its core strength is combining AI search tracking (a new, emerging market) with Reddit automation for lead generation and community engagement. The platform uses aggressive automation, AI-powered insights, and lifetime pricing to differentiate itself from traditional SEO tools. The credit-based system creates additional revenue while the freemium model drives user acquisition.

* 

